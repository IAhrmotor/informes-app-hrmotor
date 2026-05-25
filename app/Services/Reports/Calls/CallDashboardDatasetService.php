<?php

namespace App\Services\Reports\Calls;

use App\Models\SalesforceCall;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CallDashboardDatasetService
{
    private const CACHE_TTL_MINUTES = 10;

    public function __construct(
        private readonly CallMetricsAggregator $aggregator,
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly CallClassificationRules $rules,
    ) {
    }

    public function summary(Request $request): array
    {
        return $this->payload($request)['summary'];
    }

    public function agentRows(Request $request): array
    {
        $payload = $this->payload($request);

        return [
            'ok' => true,
            'teams' => $payload['teams'],
            'agents' => $payload['agents'],
            'commercials' => $payload['commercials'],
            'customer_service' => $payload['customer_service'],
            'contact_center' => $payload['contact_center'],
            'appraisers' => $payload['appraisers'],
            'items' => $payload['agents'],
        ];
    }

    public function delegationRows(Request $request): array
    {
        $payload = $this->payload($request);

        return [
            'ok' => true,
            'zones' => $payload['zones'],
            'delegations' => $payload['delegations'],
            'items' => $payload['delegations'],
        ];
    }

    public function portalRows(Request $request): array
    {
        return ['ok' => true, 'items' => $this->payload($request)['portals']];
    }

    public function payload(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return Cache::remember(
            'calls-dashboard-v1:'.md5(json_encode([
                'filters' => $filters,
                'periods' => [
                    'current' => $this->periodPayload($periods['current']),
                    'previous' => $this->periodPayload($periods['previous']),
                ],
                'version' => $this->dataVersion(),
            ])),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildPayload($filters, $periods)
        );
    }

    private function buildPayload(array $filters, array $periods): array
    {
        $current = $this->aggregate($filters, $periods['current']);
        $previous = $this->aggregate($filters, $periods['previous']);

        return [
            'summary' => [
                'ok' => $current['bucket']['total_calls'] > 0 || $previous['bucket']['total_calls'] > 0,
                'message' => $current['bucket']['total_calls'] > 0 ? null : 'No hay llamadas sincronizadas para el periodo seleccionado.',
                'periodo_actual' => $this->periodPayload($periods['current']),
                'periodo_comparado' => $this->periodPayload($periods['previous']),
                'datos_actualizados' => $this->lastUpdated()?->toDateTimeString(),
                'kpis' => $current['bucket'],
                'comparativa' => $this->comparison($current['bucket'], $previous['bucket']),
                'insights' => $this->insights($current),
                'filters' => $this->filterOptions(),
            ],
            'teams' => $current['teams'],
            'agents' => $current['agents'],
            'commercials' => $current['commercials'],
            'customer_service' => $current['customer_service'],
            'contact_center' => $current['contact_center'],
            'appraisers' => $current['appraisers'],
            'zones' => $current['zones'],
            'delegations' => $current['delegations'],
            'portals' => $current['portals'],
        ];
    }

    private function aggregate(array $filters, array $period): array
    {
        $bucket = $this->aggregator->emptyBucket();
        $teams = [];
        $agents = [];
        $zones = [];
        $delegations = [];
        $portals = [];

        $this->baseQuery($period)
            ->when($filters['direction'], fn (Builder $query) => $query->where('direction', $filters['direction']))
            ->when($filters['status'], fn (Builder $query) => $query->where('call_status', $filters['status']))
            ->when($filters['delegation'], fn (Builder $query) => $query->where('delegation', $filters['delegation']))
            ->when($filters['zone'], fn (Builder $query) => $query->where('zone', $filters['zone']))
            ->when($filters['portal'], fn (Builder $query) => $query->where('portal_resolved', $filters['portal']))
            ->when($filters['user'], function (Builder $query) use ($filters): void {
                $query->where(function (Builder $nested) use ($filters): void {
                    $nested->where('operational_user_id', $filters['user'])
                        ->orWhere('operational_user_name', $filters['user']);
                });
            })
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($filters, &$bucket, &$teams, &$agents, &$zones, &$delegations, &$portals): void {
                foreach ($rows as $call) {
                    if (! $this->matchesComputedFilters($call, $filters)) {
                        continue;
                    }

                    $origin = $this->effectiveOrigin($call);
                    $team = $this->effectiveTeam($call);
                    $this->aggregator->add($bucket, $call);

                    if ($this->rules->isOperationalTeam($team)) {
                        $this->addGroup($teams, $team, $this->teamLabel($team), [
                            'team' => $team,
                        ], $call);
                        $this->addGroup($agents, ($call->operational_user_id ?: $call->operational_user_name) ?: 'unclassified', $call->operational_user_name ?: 'Sin clasificar', [
                            'team' => $team,
                            'team_label' => $this->teamLabel($team),
                            'delegation' => $call->delegation ?: LeadDelegationNormalizer::UNCLASSIFIED,
                            'zone' => $call->zone ?: LeadDelegationNormalizer::UNCLASSIFIED,
                        ], $call);
                        $this->addGroup($zones, $call->zone ?: LeadDelegationNormalizer::UNCLASSIFIED, $call->zone ?: LeadDelegationNormalizer::UNCLASSIFIED, [], $call);
                        $this->addGroup($delegations, ($call->delegation ?: LeadDelegationNormalizer::UNCLASSIFIED).'|'.($call->zone ?: LeadDelegationNormalizer::UNCLASSIFIED), $call->delegation ?: LeadDelegationNormalizer::UNCLASSIFIED, [
                            'zone' => $call->zone ?: LeadDelegationNormalizer::UNCLASSIFIED,
                        ], $call);
                    }

                    if ($origin === 'portal') {
                        $this->addGroup($portals, $call->portal_resolved ?: CallPortalNormalizer::UNCLASSIFIED, $call->portal_resolved ?: CallPortalNormalizer::UNCLASSIFIED, [
                            'call_origin' => 'portal',
                            'call_origin_label' => $this->originLabel('portal'),
                        ], $call);
                    }
                }
            });

        $agents = $this->finalizeGroups($agents, 'user_name');

        return [
            'bucket' => $this->aggregator->finalize($bucket),
            'teams' => $this->finalizeGroups($teams, 'team_label'),
            'agents' => $agents,
            'commercials' => $this->filterAgentsByTeam($agents, 'commercial'),
            'customer_service' => $this->filterAgentsByTeam($agents, 'customer_service'),
            'contact_center' => $this->filterAgentsByTeam($agents, 'contact_center'),
            'appraisers' => $this->filterAgentsByTeam($agents, 'appraiser'),
            'zones' => $this->finalizeGroups($zones, 'zone'),
            'delegations' => $this->finalizeGroups($delegations, 'delegation'),
            'portals' => $this->finalizeGroups($portals, 'portal'),
        ];
    }

    private function baseQuery(array $period): Builder
    {
        return SalesforceCall::query()
            ->where('created_date', '>=', $period['start'])
            ->where('created_date', '<', $period['end']);
    }

    private function matchesComputedFilters(SalesforceCall $call, array $filters): bool
    {
        if ($filters['team'] && $this->effectiveTeam($call) !== $filters['team']) {
            return false;
        }

        if ($filters['origin'] && $this->effectiveOrigin($call) !== $filters['origin']) {
            return false;
        }

        return true;
    }

    private function effectiveTeam(SalesforceCall $call): string
    {
        return $this->rules->effectiveTeam(
            $call->operational_team,
            $call->operational_user_name ?: $call->owner_name,
            $call->owner_profile_name,
        );
    }

    private function effectiveOrigin(SalesforceCall $call): string
    {
        return $this->rules->effectiveOrigin($call->call_origin, $call->portales_raw);
    }

    private function addGroup(array &$groups, string $key, string $label, array $extra, SalesforceCall $call): void
    {
        $groups[$key] ??= [
            'label' => $label,
            'extra' => $extra,
            'bucket' => $this->aggregator->emptyBucket(),
        ];

        $this->aggregator->add($groups[$key]['bucket'], $call);
    }

    private function finalizeGroups(array $groups, string $labelKey): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $rows[] = array_merge($group['extra'], $this->aggregator->finalize($group['bucket']), [
                $labelKey => $group['label'],
                'nombre' => $group['label'],
            ]);
        }

        usort($rows, fn (array $a, array $b) => ($b['total_calls'] ?? 0) <=> ($a['total_calls'] ?? 0));

        return array_values($rows);
    }

    private function filterAgentsByTeam(array $agents, string $team): array
    {
        return array_values(array_filter($agents, fn (array $agent) => ($agent['team'] ?? null) === $team));
    }

    private function comparison(array $current, array $previous): array
    {
        $metrics = [
            ['key' => 'total_calls', 'label' => 'Total llamadas'],
            ['key' => 'answered', 'label' => 'Atendidas'],
            ['key' => 'not_answered', 'label' => 'No atendidas'],
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
            'Tiempo medio de conversacion: '.$this->secondsText($bucket['average_talk_seconds']).'.',
        ];
    }

    private function filters(Request $request): array
    {
        $origin = $request->string('origin')->toString();
        if ($origin === 'switchboard') {
            $origin = 'commercial_direct';
        }

        return [
            'period' => $request->string('period')->toString() ?: 'last_30_days',
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
        return [
            'teams' => collect(['commercial', 'customer_service', 'contact_center', 'appraiser', 'system'])
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
            'delegations' => $this->sortLabels(SalesforceCall::query()->distinct()->pluck('delegation')->filter()->all()),
            'zones' => $this->sortLabels(collect($this->delegationNormalizer->knownZones())
                ->merge(SalesforceCall::query()->distinct()->pluck('zone'))
                ->filter()
                ->all()),
            'portals' => $this->sortLabels(SalesforceCall::query()
                ->where('call_origin', 'portal')
                ->distinct()
                ->pluck('portal_resolved')
                ->reject(fn ($portal) => in_array($portal, [CallPortalNormalizer::COMMERCIAL_DIRECT, 'Llamada directa'], true))
                ->filter()
                ->all()),
            'users' => SalesforceCall::query()
                ->select(['operational_user_id', 'operational_user_name', 'operational_team', 'owner_name', 'owner_profile_name'])
                ->whereNotNull('operational_user_name')
                ->distinct()
                ->orderBy('operational_user_name')
                ->get()
                ->filter(fn (SalesforceCall $call) => $this->rules->isOperationalTeam($this->rules->effectiveTeam(
                    $call->operational_team,
                    $call->operational_user_name,
                    $call->owner_profile_name,
                )))
                ->map(fn (SalesforceCall $call) => [
                    'id' => $call->operational_user_id ?: $call->operational_user_name,
                    'name' => $call->operational_user_name,
                ])
                ->unique('id')
                ->values()
                ->all(),
        ];
    }

    private function sortLabels(iterable $labels): array
    {
        return collect($labels)
            ->filter()
            ->unique()
            ->sortBy(fn (string $label) => $label === LeadDelegationNormalizer::UNCLASSIFIED ? 'zzzzzz' : \Illuminate\Support\Str::ascii($label))
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

    private function dataVersion(): array
    {
        return [
            'count' => SalesforceCall::query()->count(),
            'max_id' => SalesforceCall::query()->max('id'),
            'updated_at' => SalesforceCall::query()->max('updated_at'),
            'dashboard_cache_version' => Cache::get('salesforce_calls_dashboard_cache_version', 1),
        ];
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
        $updated = SalesforceCall::query()->max('updated_at');

        return $updated ? CarbonImmutable::parse($updated) : null;
    }
}
