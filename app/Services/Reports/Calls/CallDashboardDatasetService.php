<?php

namespace App\Services\Reports\Calls;

use App\Services\Reports\Leads\LeadDelegationNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CallDashboardDatasetService
{
    private const CACHE_TTL_MINUTES = 10;

    private ?array $agentDisplayNames = null;

    private array $matchingUserIdentityCache = [];

    public function __construct(
        private readonly CallMetricsAggregator $aggregator,
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly CallClassificationRules $rules,
    ) {
    }

    public function summary(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return $this->rememberEndpoint('summary', $filters, $periods, fn () => $this->buildSummary($filters, $periods));
    }

    public function agentRows(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return $this->rememberEndpoint('agents', $filters, $periods, fn () => $this->buildAgentPayload($filters, $periods));
    }

    public function delegationRows(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return $this->rememberEndpoint('delegations', $filters, $periods, fn () => $this->buildDelegationPayload($filters, $periods));
    }

    public function portalRows(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return $this->rememberEndpoint('portals', $filters, $periods, fn () => $this->buildPortalPayload($filters, $periods));
    }

    public function payload(Request $request): array
    {
        $summary = $this->summary($request);
        $agents = $this->agentRows($request);
        $delegations = $this->delegationRows($request);
        $portals = $this->portalRows($request);

        return [
            'summary' => $summary,
            'teams' => $agents['teams'],
            'agents' => $agents['agents'],
            'commercials' => $agents['commercials'],
            'customer_service' => $agents['customer_service'],
            'contact_center' => $agents['contact_center'],
            'appraisers' => $agents['appraisers'],
            'zones' => $delegations['zones'],
            'delegations' => $delegations['delegations'],
            'portals' => $portals['items'],
        ];
    }

    private function rememberEndpoint(string $endpoint, array $filters, array $periods, callable $callback): array
    {
        return Cache::remember(
            'calls-dashboard:'.$endpoint.':'.md5(json_encode([
                'endpoint' => $endpoint,
                'filters' => $filters,
                'periods' => [
                    'current' => $this->periodPayload($periods['current']),
                    'previous' => $this->periodPayload($periods['previous']),
                ],
                'version' => $this->dataVersion(),
            ], JSON_UNESCAPED_UNICODE)),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            $callback
        );
    }

    private function buildSummary(array $filters, array $periods): array
    {
        $current = $this->summaryBucket($filters, $periods['current']);
        $previous = $this->summaryBucket($filters, $periods['previous']);

        return [
            'ok' => $current['total_calls'] > 0 || $previous['total_calls'] > 0,
            'message' => $current['total_calls'] > 0 ? null : 'No hay llamadas sincronizadas para el periodo seleccionado.',
            'periodo_actual' => $this->periodPayload($periods['current']),
            'periodo_comparado' => $this->periodPayload($periods['previous']),
            'datos_actualizados' => $this->lastUpdated()?->toDateTimeString(),
            'kpis' => $current,
            'comparativa' => $this->comparison($current, $previous),
            'insights' => $this->insights(['bucket' => $current]),
            'filters' => $this->filterOptions(),
        ];
    }

    private function buildAgentPayload(array $filters, array $periods): array
    {
        $agents = $this->agentMetricRows($filters, $periods['current']);

        return [
            'ok' => true,
            'teams' => $this->teamMetricRows($filters, $periods['current']),
            'agents' => $agents,
            'commercials' => $this->filterAgentsByTeam($agents, 'commercial'),
            'customer_service' => $this->filterAgentsByTeam($agents, 'customer_service'),
            'contact_center' => $this->filterAgentsByTeam($agents, 'contact_center'),
            'appraisers' => $this->filterAgentsByTeam($agents, 'appraiser'),
            'items' => $agents,
        ];
    }

    private function buildDelegationPayload(array $filters, array $periods): array
    {
        $zones = $this->zoneMetricRows($filters, $periods['current']);
        $delegations = $this->delegationMetricRows($filters, $periods['current']);

        return [
            'ok' => true,
            'zones' => $zones,
            'delegations' => $delegations,
            'items' => $delegations,
        ];
    }

    private function buildPortalPayload(array $filters, array $periods): array
    {
        return [
            'ok' => true,
            'items' => $this->portalMetricRows($filters, $periods['current']),
        ];
    }

    private function summaryBucket(array $filters, array $period): array
    {
        $row = $this->baseFilteredQuery($filters, $period)
            ->selectRaw($this->metricsSelectSql())
            ->first();

        return $this->finalizeBucket($this->bucketFromRow($row));
    }

    private function teamMetricRows(array $filters, array $period): array
    {
        $teamSql = $this->effectiveTeamSql();

        $rows = $this->baseFilteredQuery($filters, $period)
            ->whereRaw($this->operationalTeamConditionSql())
            ->selectRaw($teamSql.' as team')
            ->selectRaw($this->metricsSelectSql())
            ->groupByRaw($teamSql)
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $team = (string) $row->team;

            $this->addAggregatedGroup($groups, $team, $this->teamLabel($team), [
                'team' => $team,
            ], $this->bucketFromRow($row), 0);
        }

        return $this->finalizeGroups($groups, 'team_label');
    }

    private function agentMetricRows(array $filters, array $period): array
    {
        $teamSql = $this->effectiveTeamSql();
        $delegationSql = $this->effectiveDelegationSql();
        $zoneSql = $this->effectiveZoneSql();

        $rows = $this->baseFilteredQuery($filters, $period)
            ->whereRaw($this->operationalTeamConditionSql())
            ->selectRaw($teamSql.' as team')
            ->addSelect([
                'operational_user_id',
                'operational_user_name',
                'normalized_user_key',
                'destination_agent_name',
                'destination_agent_code',
                'owner_name',
                'owner_profile_name',
            ])
            ->selectRaw('MIN(id) as first_id')
            ->selectRaw($this->preferredLabelSql($delegationSql).' as delegation')
            ->selectRaw($this->preferredLabelSql($zoneSql).' as zone')
            ->selectRaw($this->metricsSelectSql())
            ->groupByRaw($teamSql)
            ->groupBy([
                'operational_user_id',
                'operational_user_name',
                'normalized_user_key',
                'destination_agent_name',
                'destination_agent_code',
                'owner_name',
                'owner_profile_name',
            ])
            ->orderBy('first_id')
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $team = (string) $row->team;

            if (! $this->rules->isOperationalTeam($team)) {
                continue;
            }

            $displayName = $this->displayUserName($row);
            $key = $this->userGroupKey($row, $team);

            $this->addAggregatedGroup($groups, $key, $displayName, [
                'team' => $team,
                'team_label' => $this->teamLabel($team),
                'delegation' => $row->delegation ?: LeadDelegationNormalizer::UNCLASSIFIED,
                'zone' => $row->zone ?: LeadDelegationNormalizer::UNCLASSIFIED,
            ], $this->bucketFromRow($row), (int) $row->first_id);
        }

        return $this->finalizeGroups($groups, 'user_name');
    }

    private function zoneMetricRows(array $filters, array $period): array
    {
        $zoneSql = $this->effectiveZoneSql();

        $rows = $this->baseFilteredQuery($filters, $period)
            ->whereRaw($this->operationalTeamConditionSql())
            ->selectRaw($zoneSql.' as zone')
            ->selectRaw($this->metricsSelectSql())
            ->groupByRaw($zoneSql)
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $zone = $row->zone ?: LeadDelegationNormalizer::UNCLASSIFIED;

            $this->addAggregatedGroup($groups, $zone, $zone, [], $this->bucketFromRow($row), 0);
        }

        return $this->finalizeGroups($groups, 'zone');
    }

    private function delegationMetricRows(array $filters, array $period): array
    {
        $delegationSql = $this->effectiveDelegationSql();
        $zoneSql = $this->effectiveZoneSql();

        $rows = $this->baseFilteredQuery($filters, $period)
            ->whereRaw($this->operationalTeamConditionSql())
            ->selectRaw($delegationSql.' as delegation')
            ->selectRaw($zoneSql.' as zone')
            ->selectRaw($this->metricsSelectSql())
            ->groupByRaw($delegationSql)
            ->groupByRaw($zoneSql)
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $delegation = $row->delegation ?: LeadDelegationNormalizer::UNCLASSIFIED;
            $zone = $row->zone ?: LeadDelegationNormalizer::UNCLASSIFIED;

            $this->addAggregatedGroup($groups, $delegation.'|'.$zone, $delegation, [
                'zone' => $zone,
            ], $this->bucketFromRow($row), 0);
        }

        return $this->finalizeGroups($groups, 'delegation');
    }

    private function portalMetricRows(array $filters, array $period): array
    {
        $portalSql = $this->portalSql();

        $rows = $this->baseFilteredQuery($filters, $period)
            ->where('call_origin', 'portal')
            ->where(function (QueryBuilder $query): void {
                $query->whereNull('portal_resolved')
                    ->orWhereNotIn('portal_resolved', [
                        CallPortalNormalizer::COMMERCIAL_DIRECT,
                        'Llamada directa',
                    ]);
            })
            ->selectRaw($portalSql.' as portal')
            ->selectRaw($this->metricsSelectSql())
            ->groupByRaw($portalSql)
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $portal = $row->portal ?: CallPortalNormalizer::UNCLASSIFIED;

            $this->addAggregatedGroup($groups, $portal, $portal, [
                'call_origin' => 'portal',
                'call_origin_label' => $this->originLabel('portal'),
            ], $this->bucketFromRow($row), 0);
        }

        return $this->finalizeGroups($groups, 'portal');
    }

    private function baseFilteredQuery(array $filters, array $period, bool $includeUser = true): QueryBuilder
    {
        return $this->applyBaseFilters(DB::table('salesforce_calls'), $filters, $period, $includeUser);
    }

    private function applyBaseFilters(QueryBuilder $query, array $filters, array $period, bool $includeUser = true): QueryBuilder
    {
        $query
            ->where('created_date', '>=', $period['start'])
            ->where('created_date', '<', $period['end']);

        if ($filters['direction'] !== '') {
            $query->where('direction', $filters['direction']);
        }

        if ($filters['status'] !== '') {
            $query->where('call_status', $filters['status']);
        }

        if ($filters['portal'] !== '') {
            $query->where('portal_resolved', $filters['portal']);
        }

        if ($filters['team'] !== '') {
            $query->whereRaw($this->effectiveTeamSql().' = ?', [$filters['team']]);
        }

        if ($filters['origin'] !== '') {
            $query->whereRaw($this->effectiveOriginSql().' = ?', [$filters['origin']]);
        }

        if ($filters['delegation'] !== '') {
            $query->whereRaw($this->effectiveDelegationSql().' = ?', [$filters['delegation']]);
        }

        if ($filters['zone'] !== '') {
            $query->whereRaw($this->effectiveZoneSql().' = ?', [$filters['zone']]);
        }

        if ($includeUser && $filters['user'] !== '') {
            $this->applyUserFilter($query, $filters, $period);
        }

        return $query;
    }

    private function applyUserFilter(QueryBuilder $query, array $filters, array $period): void
    {
        $filter = $filters['user'];
        $team = $this->teamFromUserFilter($filter);
        $identities = $this->matchingUserIdentities($filters, $period);

        if ($team !== null) {
            $query->whereRaw($this->effectiveTeamSql().' = ?', [$team]);
        }

        $query->where(function (QueryBuilder $query) use ($filter, $identities): void {
            $query
                ->where('operational_user_id', $filter)
                ->orWhere('operational_user_name', $filter)
                ->orWhere('normalized_user_key', $filter)
                ->orWhere('destination_agent_name', $filter)
                ->orWhere('owner_name', $filter);

            foreach ($identities as $identity) {
                foreach (['operational_user_id', 'operational_user_name', 'normalized_user_key', 'destination_agent_name', 'owner_name'] as $column) {
                    $value = data_get($identity, $column);

                    if (filled($value)) {
                        $query->orWhere($column, $value);
                    }
                }
            }
        });
    }

    private function matchingUserIdentities(array $filters, array $period): array
    {
        $filter = $filters['user'];
        $cacheKey = md5(json_encode([
            'filter' => $filter,
            'filters' => array_merge($filters, ['user' => '']),
            'period' => $this->periodPayload($period),
        ], JSON_UNESCAPED_UNICODE));

        if (array_key_exists($cacheKey, $this->matchingUserIdentityCache)) {
            return $this->matchingUserIdentityCache[$cacheKey];
        }

        $teamFilter = $this->teamFromUserFilter($filter);
        $lookupFilters = array_merge($filters, ['user' => '']);

        if ($teamFilter !== null && $lookupFilters['team'] === '') {
            $lookupFilters['team'] = $teamFilter;
        }

        $rows = $this->identityRowsQuery($lookupFilters, $period)->get();
        $matches = [];

        foreach ($rows as $row) {
            $team = (string) $row->team;
            $displayName = $this->displayUserName($row);
            $key = $this->userGroupKey($row, $team);

            if ($key === $filter
                || (string) $row->operational_user_id === $filter
                || (string) $row->operational_user_name === $filter
                || (string) $row->normalized_user_key === $filter
                || (string) $row->destination_agent_name === $filter
                || (string) $row->owner_name === $filter
                || $displayName === $filter
            ) {
                $matches[] = $row;
            }
        }

        return $this->matchingUserIdentityCache[$cacheKey] = $matches;
    }

    private function identityRowsQuery(?array $filters = null, ?array $period = null): QueryBuilder
    {
        $teamSql = $this->effectiveTeamSql();
        $query = DB::table('salesforce_calls');

        if ($filters !== null && $period !== null) {
            $query = $this->applyBaseFilters($query, $filters, $period, false);
        }

        return $query
            ->whereRaw($this->operationalTeamConditionSql())
            ->selectRaw($teamSql.' as team')
            ->addSelect([
                'operational_user_id',
                'operational_user_name',
                'normalized_user_key',
                'destination_agent_name',
                'destination_agent_code',
                'owner_name',
                'owner_profile_name',
            ])
            ->selectRaw('MIN(id) as first_id')
            ->groupByRaw($teamSql)
            ->groupBy([
                'operational_user_id',
                'operational_user_name',
                'normalized_user_key',
                'destination_agent_name',
                'destination_agent_code',
                'owner_name',
                'owner_profile_name',
            ])
            ->orderBy('first_id');
    }

    private function teamFromUserFilter(string $filter): ?string
    {
        if (! str_contains($filter, '|')) {
            return null;
        }

        $team = Str::before($filter, '|');

        return $this->rules->isOperationalTeam($team) ? $team : null;
    }

    private function metricsSelectSql(): string
    {
        $originSql = $this->effectiveOriginSql();
        $teamSql = $this->effectiveTeamSql();
        $answeredSql = $this->answeredConditionSql();
        $lostSql = $this->lostConditionSql();
        $overflowSql = $this->overflowConditionSql();
        $overflowDenominatorSql = $this->overflowDenominatorConditionSql();

        return implode(",\n", [
            'COUNT(*) as total_calls',
            "SUM(CASE WHEN {$originSql} = 'commercial_direct' THEN 1 ELSE 0 END) as commercial_direct_calls",
            "SUM(CASE WHEN {$originSql} = 'commercial_direct' AND {$answeredSql} THEN 1 ELSE 0 END) as commercial_direct_answered",
            "SUM(CASE WHEN {$originSql} = 'commercial_direct' AND {$lostSql} THEN 1 ELSE 0 END) as commercial_direct_lost",
            "SUM(CASE WHEN {$originSql} = 'portal' THEN 1 ELSE 0 END) as portal_calls",
            "SUM(CASE WHEN {$originSql} = 'portal' AND {$answeredSql} THEN 1 ELSE 0 END) as portal_answered",
            "SUM(CASE WHEN {$originSql} = 'portal' AND {$lostSql} THEN 1 ELSE 0 END) as portal_lost",
            "SUM(CASE WHEN {$answeredSql} THEN 1 ELSE 0 END) as answered",
            "SUM(CASE WHEN {$lostSql} THEN 1 ELSE 0 END) as not_answered",
            "SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound",
            "SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound",
            "SUM(CASE WHEN direction = 'unknown' THEN 1 ELSE 0 END) as unknown_direction",
            "SUM(CASE WHEN {$answeredSql} AND {$teamSql} = 'commercial' THEN 1 ELSE 0 END) as answered_commercial",
            "SUM(CASE WHEN {$answeredSql} AND {$teamSql} = 'customer_service' THEN 1 ELSE 0 END) as answered_customer_service",
            "SUM(CASE WHEN {$answeredSql} AND {$teamSql} = 'contact_center' THEN 1 ELSE 0 END) as answered_contact_center",
            "SUM(CASE WHEN {$answeredSql} AND {$teamSql} = 'appraiser' THEN 1 ELSE 0 END) as answered_appraiser",
            "SUM(CASE WHEN {$answeredSql} AND adjusted_duration_seconds IS NOT NULL THEN adjusted_duration_seconds ELSE 0 END) as adjusted_duration_answered_sum",
            "SUM(CASE WHEN {$answeredSql} AND adjusted_duration_seconds IS NOT NULL THEN 1 ELSE 0 END) as answered_duration_count",
            "SUM(CASE WHEN {$overflowSql} THEN 1 ELSE 0 END) as overflow_count",
            "SUM(CASE WHEN {$overflowDenominatorSql} THEN 1 ELSE 0 END) as overflow_denominator",
            "AVG(CASE WHEN {$answeredSql} THEN adjusted_duration_seconds ELSE NULL END) as avg_talk_seconds",
        ]);
    }

    private function answeredConditionSql(): string
    {
        return "(COALESCE(call_status = 'answered', 0) OR COALESCE(is_answered, 0) = 1)";
    }

    private function lostConditionSql(): string
    {
        $answeredSql = $this->answeredConditionSql();

        return "((NOT {$answeredSql}) OR call_status = 'not_answered' OR COALESCE(is_lost, 0) = 1)";
    }

    private function overflowConditionSql(): string
    {
        $originSql = $this->effectiveOriginSql();
        $teamSql = $this->effectiveTeamSql();
        $answeredSql = $this->answeredConditionSql();
        $portalSql = $this->overflowPortalKeySql();

        return "(COALESCE(is_overflow, 0) = 1 OR ({$originSql} = 'portal'
            AND {$answeredSql}
            AND {$portalSql} NOT IN ('web', 'google maps')
            AND {$teamSql} IN ('contact_center', 'customer_service')))";
    }

    private function overflowDenominatorConditionSql(): string
    {
        $originSql = $this->effectiveOriginSql();
        $answeredSql = $this->answeredConditionSql();
        $portalSql = $this->overflowPortalKeySql();

        return "({$originSql} = 'portal'
            AND {$answeredSql}
            AND {$portalSql} NOT IN ('web', 'google maps'))";
    }

    private function overflowPortalKeySql(): string
    {
        return "LOWER(TRIM(COALESCE(portal_resolved, '')))";
    }

    private function effectiveOriginSql(): string
    {
        return "CASE
            WHEN call_origin = 'switchboard' THEN 'commercial_direct'
            WHEN call_origin = 'commercial_direct' THEN 'commercial_direct'
            WHEN portales_raw IS NULL OR TRIM(portales_raw) = '' OR LOWER(TRIM(portales_raw)) = 'llamada directa' THEN 'commercial_direct'
            ELSE 'portal'
        END";
    }

    private function effectiveTeamSql(): string
    {
        $nameSql = "LOWER(TRIM(COALESCE(NULLIF(operational_user_name, ''), NULLIF(owner_name, ''), '')))";
        $compactNameSql = "REPLACE({$nameSql}, ' ', '')";
        $profileSql = "LOWER(COALESCE(owner_profile_name, ''))";

        return "CASE
            WHEN {$nameSql} IN ('carlos torres', 'platform integration user', 'api user')
                OR {$profileSql} LIKE '%administrator%'
                THEN 'system'
            WHEN {$compactNameSql} IN ('vanessasanjuan', 'vanesasanjuan', 'callcenterfontellas')
                THEN 'customer_service'
            WHEN operational_team IN ('commercial', 'customer_service', 'contact_center', 'appraiser', 'system')
                THEN operational_team
            ELSE 'appraiser'
        END";
    }

    private function effectiveDelegationSql(): string
    {
        $teamSql = $this->effectiveTeamSql();

        return "CASE
            WHEN {$teamSql} = 'customer_service' THEN ".$this->sqlString(CallClassificationRules::CUSTOMER_SERVICE_LABEL)."
            WHEN {$teamSql} = 'contact_center' THEN ".$this->sqlString(CallClassificationRules::CONTACT_CENTER_LABEL)."
            ELSE COALESCE(NULLIF(delegation, ''), ".$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED).')
        END';
    }

    private function effectiveZoneSql(): string
    {
        $teamSql = $this->effectiveTeamSql();

        return "CASE
            WHEN {$teamSql} = 'customer_service' THEN ".$this->sqlString(CallClassificationRules::CUSTOMER_SERVICE_LABEL)."
            WHEN {$teamSql} = 'contact_center' THEN ".$this->sqlString(CallClassificationRules::CONTACT_CENTER_LABEL)."
            ELSE COALESCE(NULLIF(zone, ''), ".$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED).')
        END';
    }

    private function operationalTeamConditionSql(): string
    {
        return $this->effectiveTeamSql()." IN ('commercial', 'customer_service', 'contact_center', 'appraiser')";
    }

    private function preferredLabelSql(string $labelSql): string
    {
        return 'COALESCE(MAX(NULLIF('.$labelSql.', '.$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED).')), '.$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED).')';
    }

    private function portalSql(): string
    {
        return 'COALESCE(NULLIF(portal_resolved, \'\'), '.$this->sqlString(CallPortalNormalizer::UNCLASSIFIED).')';
    }

    private function sqlString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function bucketFromRow(mixed $row): array
    {
        $bucket = $this->aggregator->emptyBucket();

        foreach (array_keys($bucket) as $key) {
            $bucket[$key] = (int) (data_get($row, $key, 0) ?? 0);
        }

        return $bucket;
    }

    private function finalizeBucket(array $bucket): array
    {
        $result = $this->aggregator->finalize(array_merge($this->aggregator->emptyBucket(), $bucket));
        $result['avg_talk_seconds'] = $result['average_talk_seconds'];
        $result['inbound_calls'] = $result['inbound'];
        $result['outbound_calls'] = $result['outbound'];
        $result['answered_by_commercial'] = $result['answered_commercial'];
        $result['answered_by_customer_service'] = $result['answered_customer_service'];
        $result['answered_by_contact_center'] = $result['answered_contact_center'];
        $result['answered_by_appraiser'] = $result['answered_appraiser'];
        $result['overflow_count'] = $result['overflow_count'] ?? 0;
        $result['overflows'] = $result['overflow_count'];

        return $result;
    }

    private function addAggregatedGroup(array &$groups, string $key, string $label, array $extra, array $bucket, int $firstId): void
    {
        if (! isset($groups[$key])) {
            $groups[$key] = [
                'label' => $label,
                'extra' => $extra,
                'bucket' => $this->aggregator->emptyBucket(),
                'first_id' => $firstId,
            ];
        }

        foreach ($bucket as $metric => $value) {
            $groups[$key]['bucket'][$metric] = ($groups[$key]['bucket'][$metric] ?? 0) + (int) $value;
        }
    }

    private function finalizeGroups(array $groups, string $labelKey): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $rows[] = array_merge($group['extra'], $this->finalizeBucket($group['bucket']), [
                $labelKey => $group['label'],
                'nombre' => $group['label'],
            ]);
        }

        usort($rows, fn (array $a, array $b) => [-(int) ($a['total_calls'] ?? 0), Str::ascii((string) ($a[$labelKey] ?? ''))]
            <=> [-(int) ($b['total_calls'] ?? 0), Str::ascii((string) ($b[$labelKey] ?? ''))]);

        return array_values($rows);
    }

    private function filterAgentsByTeam(array $agents, string $team): array
    {
        return array_values(array_filter($agents, fn (array $agent) => ($agent['team'] ?? null) === $team));
    }

    private function userGroupKey(mixed $call, string $team): string
    {
        return $this->rules->userGroupKey(
            $team,
            data_get($call, 'operational_user_id'),
            data_get($call, 'normalized_user_key') ?: $this->displayUserName($call),
            data_get($call, 'destination_agent_name'),
            data_get($call, 'owner_name'),
            data_get($call, 'owner_profile_name'),
        );
    }

    private function displayUserName(mixed $call): string
    {
        $mapped = $this->mappedAgentName($call);

        if ($mapped !== null) {
            return $this->rules->canonicalUserName($mapped);
        }

        return $this->rules->canonicalUserName(
            data_get($call, 'operational_user_name'),
            data_get($call, 'destination_agent_name'),
            data_get($call, 'owner_name'),
        );
    }

    private function mappedAgentName(mixed $call): ?string
    {
        $names = $this->agentDisplayNames();
        $code = filled(data_get($call, 'destination_agent_code')) ? Str::upper(data_get($call, 'destination_agent_code')) : null;

        if ($code && isset($names['by_code'][$code])) {
            return $names['by_code'][$code];
        }

        foreach ([data_get($call, 'operational_user_name'), data_get($call, 'destination_agent_name'), data_get($call, 'owner_name')] as $candidate) {
            $key = $this->rules->normalizeName($candidate);

            if ($key !== '' && isset($names['by_name'][$key])) {
                return $names['by_name'][$key];
            }
        }

        return null;
    }

    private function agentDisplayNames(): array
    {
        if ($this->agentDisplayNames !== null) {
            return $this->agentDisplayNames;
        }

        $byCode = [];
        $byName = [];

        DB::table('call_agent_mappings')
            ->where('active', true)
            ->get()
            ->each(function (mixed $mapping) use (&$byCode, &$byName): void {
                if (filled($mapping->agent_code)) {
                    $byCode[Str::upper($mapping->agent_code)] = $mapping->user_name;
                }

                $key = $mapping->normalized_name ?: $this->rules->normalizeName($mapping->user_name);

                if ($key !== '') {
                    $byName[$key] = $mapping->user_name;
                }
            });

        return $this->agentDisplayNames = ['by_code' => $byCode, 'by_name' => $byName];
    }

    private function comparison(array $current, array $previous): array
    {
        $metrics = [
            ['key' => 'total_calls', 'label' => 'Total llamadas'],
            ['key' => 'answered', 'label' => 'Atendidas'],
            ['key' => 'not_answered', 'label' => 'No atendidas'],
            ['key' => 'overflow_count', 'label' => 'Desbordes'],
            ['key' => 'inbound', 'label' => 'Entrantes'],
            ['key' => 'outbound', 'label' => 'Salientes'],
            ['key' => 'average_talk_seconds', 'label' => 'Tiempo medio conversacion', 'seconds' => true],
        ];

        return array_map(fn (array $metric) => [
            'key' => $metric['key'],
            'metrica' => $metric['label'],
            'periodo_actual' => $current[$metric['key']] ?? 0,
            'periodo_comparado' => $previous[$metric['key']] ?? 0,
            'diferencia' => round(($current[$metric['key']] ?? 0) - ($previous[$metric['key']] ?? 0), 2),
            'is_seconds' => (bool) ($metric['seconds'] ?? false),
        ], $metrics);
    }

    private function insights(array $current): array
    {
        $bucket = $current['bucket'];

        if ($bucket['total_calls'] === 0) {
            return ['No hay llamadas sincronizadas para el periodo seleccionado.'];
        }

        return [
            'Atendidas: '.$bucket['answered'].' de '.$bucket['total_calls'].' llamadas.',
            'No atendidas o perdidas: '.$bucket['not_answered'].'.',
            'Desbordes: '.$bucket['overflow_count'].'.',
            'Tiempo medio de conversacion: '.$this->secondsText($bucket['average_talk_seconds']).'.',
        ];
    }

    private function filters(Request $request): array
    {
        $origin = $request->string('origin')->toString();
        if ($origin === 'switchboard') {
            $origin = 'commercial_direct';
        }

        $period = $request->string('period')->toString() ?: 'last_30_days';
        $period = match ($period) {
            'month_current' => 'current_month',
            'month_previous' => 'previous_month',
            default => $period,
        };

        return [
            'period' => $period,
            'current_start' => $request->string('current_start')->toString(),
            'current_end' => $request->string('current_end')->toString(),
            'comparison_start' => $request->string('comparison_start')->toString(),
            'comparison_end' => $request->string('comparison_end')->toString(),
            'team' => $request->string('team')->toString(),
            'direction' => $request->string('direction')->toString(),
            'status' => $request->string('status')->toString(),
            'origin' => $origin,
            'delegation' => $request->string('delegation')->toString(),
            'zone' => $request->string('zone')->toString(),
            'portal' => $request->string('portal')->toString(),
            'user' => $request->string('user')->toString(),
        ];
    }

    private function periods(array $filters): array
    {
        $now = CarbonImmutable::now();

        if ($filters['period'] === 'custom') {
            $currentStart = $this->parseDate($filters['current_start'], $now->subDays(30))->startOfDay();
            $currentEnd = $this->parseDate($filters['current_end'], $now)->endOfDay();
            $comparisonStart = $this->parseDate($filters['comparison_start'], $currentStart->subDays((int) floor($currentStart->diffInDays($currentEnd)) + 1))->startOfDay();
            $comparisonEnd = $this->parseDate($filters['comparison_end'], $currentStart->subDay())->endOfDay();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $comparisonStart, 'end' => $comparisonEnd],
            ];
        }

        if ($filters['period'] === 'current_month') {
            $currentStart = $now->startOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $now],
                'previous' => ['start' => $currentStart->subMonthNoOverflow(), 'end' => $currentStart->subDay()->endOfDay()],
            ];
        }

        if ($filters['period'] === 'previous_month') {
            $currentStart = $now->subMonthNoOverflow()->startOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentStart->endOfMonth()],
                'previous' => ['start' => $currentStart->subMonthNoOverflow()->startOfMonth(), 'end' => $currentStart->subMonthNoOverflow()->endOfMonth()],
            ];
        }

        return [
            'current' => ['start' => $now->subDays(30), 'end' => $now],
            'previous' => ['start' => $now->subDays(60), 'end' => $now->subDays(30)],
        ];
    }

    private function filterOptions(): array
    {
        return Cache::remember(
            'calls-dashboard:filters:'.md5((string) $this->dataVersion()),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => [
                'teams' => collect(['commercial', 'customer_service', 'contact_center', 'appraiser'])
                    ->map(fn (string $id) => ['id' => $id, 'name' => $this->teamLabel($id)])
                    ->all(),
                'directions' => [
                    ['id' => 'inbound', 'name' => 'Entrante'],
                    ['id' => 'outbound', 'name' => 'Saliente'],
                    ['id' => 'unknown', 'name' => 'Sin clasificar'],
                ],
                'statuses' => [
                    ['id' => 'answered', 'name' => 'Atendida'],
                    ['id' => 'not_answered', 'name' => 'No atendida'],
                ],
                'origins' => [
                    ['id' => 'commercial_direct', 'name' => 'Llamada directa a comercial'],
                    ['id' => 'portal', 'name' => 'Portal / Procedencia'],
                ],
                'delegations' => $this->sortLabels(collect([
                    CallClassificationRules::CUSTOMER_SERVICE_LABEL,
                    CallClassificationRules::CONTACT_CENTER_LABEL,
                ])->merge($this->distinctEffectiveLabels($this->effectiveDelegationSql()))->all()),
                'zones' => $this->sortLabels(collect($this->delegationNormalizer->knownZones())
                    ->merge([CallClassificationRules::CUSTOMER_SERVICE_LABEL, CallClassificationRules::CONTACT_CENTER_LABEL])
                    ->merge($this->distinctEffectiveLabels($this->effectiveZoneSql()))
                    ->all()),
                'portals' => $this->sortLabels(DB::table('salesforce_calls')
                    ->where('call_origin', 'portal')
                    ->where(function (QueryBuilder $query): void {
                        $query->whereNull('portal_resolved')
                            ->orWhereNotIn('portal_resolved', [
                                CallPortalNormalizer::COMMERCIAL_DIRECT,
                                'Llamada directa',
                            ]);
                    })
                    ->distinct()
                    ->pluck('portal_resolved')
                    ->filter()
                    ->all()),
                'users' => $this->userFilterOptions(),
            ]
        );
    }

    private function distinctEffectiveLabels(string $labelSql): array
    {
        return DB::table('salesforce_calls')
            ->selectRaw($labelSql.' as label')
            ->whereRaw($this->operationalTeamConditionSql())
            ->groupByRaw($labelSql)
            ->pluck('label')
            ->filter()
            ->all();
    }

    private function userFilterOptions(): array
    {
        $options = [];

        foreach ($this->identityRowsQuery()->get() as $row) {
            $team = (string) $row->team;

            if (! $this->rules->isOperationalTeam($team)) {
                continue;
            }

            $key = $this->userGroupKey($row, $team);

            $options[$key] ??= [
                'id' => $key,
                'name' => $this->displayUserName($row),
            ];
        }

        return collect($options)
            ->sortBy(fn (array $user) => Str::ascii($user['name']))
            ->values()
            ->all();
    }

    private function sortLabels(iterable $labels): array
    {
        return collect($labels)
            ->filter()
            ->unique()
            ->sortBy(fn (string $label) => $label === LeadDelegationNormalizer::UNCLASSIFIED ? 'zzzzzz' : Str::ascii($label))
            ->values()
            ->all();
    }

    private function teamLabel(?string $team): string
    {
        return match ($team) {
            'commercial' => 'Comerciales',
            'customer_service' => 'Atencion al Cliente',
            'contact_center' => 'Contact Center',
            'appraiser' => 'Tasadores',
            'system' => 'Sistema / Sin agente',
            default => 'Sin clasificar',
        };
    }

    private function originLabel(?string $origin): string
    {
        return match ($origin) {
            'commercial_direct' => 'Llamada directa a comercial',
            default => 'Portal / Procedencia',
        };
    }

    private function secondsText(int|float $seconds): string
    {
        $seconds = (int) round($seconds);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function dataVersion(): int
    {
        return (int) Cache::get('salesforce_calls_dashboard_cache_version', 1);
    }

    private function periodPayload(array $period): array
    {
        return [
            'inicio' => CarbonImmutable::parse($period['start'])->toDateString(),
            'fin' => CarbonImmutable::parse($period['end'])->toDateString(),
        ];
    }

    private function parseDate(?string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (blank($value)) {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function lastUpdated(): ?CarbonImmutable
    {
        $updated = DB::table('salesforce_calls')->max('updated_at');

        return $updated ? CarbonImmutable::parse($updated) : null;
    }
}
