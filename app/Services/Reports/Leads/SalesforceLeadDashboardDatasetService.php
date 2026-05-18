<?php

namespace App\Services\Reports\Leads;

use App\Models\MasterPortal;
use App\Models\MonthlyCommercialReportSnapshot;
use App\Models\SalesforceLead;
use App\Models\SalesforceLeadActivitySummary;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SalesforceLeadDashboardDatasetService
{
    private const COMMERCIAL_PROFILES = [
        'Compra/Venta',
        'Comerciales Partner Community',
    ];

    private const CACHE_TTL_MINUTES = 10;

    private const LEAD_TYPES = [
        'Tasación',
        'Venta',
    ];

    // Dirección quiere que los no clasificados cuenten en KPIs generales por ahora.
    private const INCLUDE_UNCLASSIFIED_IN_TOTALS = true;

    private ?Collection $commercialUsersCache = null;
    private ?Collection $portalMapCache = null;
    public function __construct(
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly LeadDashboardAiInsightsService $aiInsights,
    ) {
    }

    public function payload(Request $request, string $context = 'summary'): array
    {
        $filters = $this->filters($request, $context);
        $periods = $this->periods($filters);

        return Cache::remember(
            $this->cacheKey($filters, $periods),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildPayload($filters, $periods)
        );
    }

    public function summary(Request $request): array
    {
        return $this->payload($request, 'summary')['summary'];
    }

    public function commercialRows(Request $request): array
    {
        $payload = $this->payload($request, 'commercials');

        return [
            'ok' => true,
            'zones' => $payload['commercial_zones'],
            'delegations' => $payload['commercial_delegations'],
            'commercials' => $payload['commercials'],
            'items' => $payload['commercials'],
        ];
    }

    public function delegationRows(Request $request): array
    {
        return ['items' => $this->payload($request, 'delegations')['delegations']];
    }

    public function portalRows(Request $request): array
    {
        return ['items' => $this->payload($request, 'portals')['portals']];
    }

    public function filters(Request $request, string $context = 'summary'): array
    {
        return [
            'context' => $context,
            'period' => $request->string('period')->toString() ?: 'last_30_days',
            'current_start' => $request->string('current_start')->toString(),
            'current_end' => $request->string('current_end')->toString(),
            'comparison_start' => $request->string('comparison_start')->toString(),
            'comparison_end' => $request->string('comparison_end')->toString(),
            'portal' => $request->string('portal')->toString(),
            'lead_delegation' => $request->string('lead_delegation')->toString()
                ?: $request->string('delegation')->toString(),
            'lead_type' => $request->string('lead_type')->toString(),
            'commercial_delegation' => $request->string('commercial_delegation')->toString(),
            'zone' => $request->string('zone')->toString(),
            'commercial' => $request->string('commercial')->toString(),
            'exposition_mode' => $request->string('exposition_mode')->toString() ?: 'with',
        ];
    }

    public function periods(array $filters): array
    {
        $now = CarbonImmutable::now();
        $period = $filters['period'] ?: 'last_30_days';

        if ($period === 'custom') {
            $currentStart = $this->parseDate($filters['current_start'], $now->subDays(30)->startOfDay())->startOfDay();
            $currentEnd = $this->parseDate($filters['current_end'], $now)->endOfDay();
            $comparisonStart = $this->parseDate($filters['comparison_start'], $currentStart->subDays((int) floor($currentStart->diffInDays($currentEnd)) + 1))->startOfDay();
            $comparisonEnd = $this->parseDate($filters['comparison_end'], $currentStart->subDay())->endOfDay();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $comparisonStart, 'end' => $comparisonEnd],
            ];
        }

        if ($period === 'current_month') {
            $currentStart = $now->startOfMonth();
            $currentEnd = $now;
            $previousStart = $currentStart->subMonthNoOverflow();
            $previousEndCandidate = $previousStart->addDays((int) floor($currentStart->diffInDays($currentEnd)))->endOfDay();
            $previousEnd = $previousEndCandidate->lessThanOrEqualTo($previousStart->endOfMonth())
                ? $previousEndCandidate
                : $previousStart->endOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $previousStart, 'end' => $previousEnd],
            ];
        }

        if ($period === 'previous_month') {
            $currentStart = $now->subMonthNoOverflow()->startOfMonth();
            $currentEnd = $now->subMonthNoOverflow()->endOfMonth();
            $previousStart = $currentStart->subMonthNoOverflow()->startOfMonth();
            $previousEnd = $currentStart->subMonthNoOverflow()->endOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $previousStart, 'end' => $previousEnd],
            ];
        }

        return [
            'current' => ['start' => $now->subDays(30), 'end' => $now],
            'previous' => ['start' => $now->subDays(60), 'end' => $now->subDays(30)],
        ];
    }

    public function decorateLead(mixed $lead, mixed $summary = null, ?CarbonInterface $referenceDate = null): array
    {
        $referenceDate = $referenceDate ? CarbonImmutable::parse($referenceDate) : CarbonImmutable::now();
        $status = (string) data_get($lead, 'status', '');
        $isConverted = $status === 'Convertido';
        $isDiscarded = $status === 'Descartado';
        $isPotential = $status === 'Potencial';
        $channel = $this->resolveChannel(data_get($lead, 'medio_nuevo'));
        $portal = $this->resolvePortal($lead, $channel);
        $leadDelegation = $this->resolveLeadDelegation($lead);
        $manager = $this->resolveSimplifiedManager($lead, $isConverted, $isDiscarded);
        $commercialUser = $manager['id'] ? $this->commercialUsers()->get($manager['id']) : null;
        $commercialDelegation = $this->normalizeCommercialDelegation(data_get($commercialUser, 'user_delegation'));

        $totalActivities = (int) (data_get($summary, 'total_actividades') ?? 0);
        $lastActivity = data_get($summary, 'fecha_ultima_actividad');
        $lastActivityAt = $lastActivity ? CarbonImmutable::parse($lastActivity) : null;
        $hasRecentActivity = $lastActivityAt !== null
            && $lastActivityAt->lessThanOrEqualTo($referenceDate)
            && $lastActivityAt->greaterThanOrEqualTo($referenceDate->subDays(3));
        $potentialWithoutWork = $isPotential && ($totalActivities === 0 || ! $hasRecentActivity);
        $managed = $isConverted || $isDiscarded || ($isPotential && $hasRecentActivity);

        return [
            'id' => data_get($lead, 'id'),
            'salesforce_id' => data_get($lead, 'salesforce_id'),
            'status' => $status,
            'lead_type' => data_get($lead, 'record_type_name'),
            'is_convertido' => $isConverted,
            'is_descartado' => $isDiscarded,
            'is_potencial' => $isPotential,
            'is_potencial_sin_trabajar' => $potentialWithoutWork,
            'is_gestionado' => $managed,
            'is_llamada' => $channel === 'Llamada',
            'is_formulario' => $channel === 'Formulario',
            'canal' => $channel,
            'portal' => $portal,
            'grupo_portal' => $this->portalGroup($portal),
            'lead_delegation' => $leadDelegation['delegation'],
            'lead_group' => $leadDelegation['group'],
            'lead_zone' => $leadDelegation['zone'],
            'lead_delegation_raw' => $leadDelegation['raw'],
            'lead_delegation_is_classified' => $leadDelegation['is_classified'],
            'commercial_delegation' => $commercialDelegation['delegation'],
            'commercial_group' => $commercialDelegation['group'],
            'commercial_zone' => $commercialDelegation['zone'],
            'commercial_delegation_raw' => $commercialDelegation['raw'],
            'commercial_delegation_is_classified' => $commercialDelegation['is_classified'],
            'zona' => $commercialDelegation['zone'],
            'gestor_id' => $manager['id'],
            'gestor_nombre' => data_get($commercialUser, 'name') ?? $manager['name'],
            'gestor_es_comercial' => $commercialUser !== null,
            'is_exposicion' => Str::lower($portal) === Str::lower('Exposición'),
            'total_actividades' => $totalActivities,
            'fecha_ultima_actividad' => $lastActivityAt,
        ];
    }

    private function buildPayload(array $filters, array $periods): array
    {
        $current = $this->emptyBucket();
        $previous = $this->emptyBucket();
        $commercialZoneGroups = [];
        $commercialDelegationGroups = [];
        $commercialGroups = [];
        $delegationGroups = [];
        $portalGroups = [];
        $currentRows = $this->decoratedLeadsForPeriod($periods['current']);
        $previousRows = $this->decoratedLeadsForPeriod($periods['previous']);

        foreach ($currentRows as $lead) {
            if (! $this->passesFilters($lead, $filters)) {
                continue;
            }

            $this->addToBucket($current, $lead);

            if ($lead['gestor_es_comercial']) {
                $this->addGroup($commercialZoneGroups, $lead['commercial_zone'], $lead['commercial_zone'], [], $lead);
                $this->addGroup($commercialDelegationGroups, $lead['commercial_delegation'].'|'.$lead['commercial_zone'], $lead['commercial_delegation'], [
                    'zone' => $lead['commercial_zone'],
                ], $lead);
                $this->addGroup($commercialGroups, $lead['gestor_id'], $lead['gestor_nombre'], [
                    'commercial_delegation' => $lead['commercial_delegation'],
                    'zone' => $lead['commercial_zone'],
                ], $lead);
            }

            $this->addGroup($delegationGroups, $lead['commercial_zone'].'|'.$lead['lead_delegation'], $lead['lead_delegation'], [
                'zone' => $lead['commercial_zone'],
            ], $lead);

            $this->addGroup($portalGroups, $lead['portal'], $lead['portal'], [], $lead);
        }

        foreach ($previousRows as $lead) {
            if (! $this->passesFilters($lead, $filters)) {
                continue;
            }

            $this->addToBucket($previous, $lead);
        }

        $current = $this->finalizeBucket($current);
        $previous = $this->finalizeBucket($previous);
        $commercialZones = $this->finalizeGroups($commercialZoneGroups, 'zone');
        $commercialDelegations = $this->finalizeGroups($commercialDelegationGroups, 'commercial_delegation');
        $commercials = $this->finalizeGroups($commercialGroups, 'comercial');
        $delegations = $this->finalizeGroups($delegationGroups, 'lead_delegation');
        $portals = $this->finalizeGroups($portalGroups, 'portal');
        $comparison = $this->compactComparison($current, $previous);
        $aiPayload = $this->aiPayload($filters, $periods, $current, $previous, $comparison, $portals, $commercials, $delegations);
        $executiveInsights = $this->aiInsights->generate($aiPayload);

        return [
            'summary' => [
                'ok' => $current['leads_totales'] > 0 || $previous['leads_totales'] > 0,
                'message' => $current['leads_totales'] > 0 ? null : 'No hay datos sincronizados para el periodo seleccionado.',
                'periodo_actual' => $this->periodPayload($periods['current']),
                'periodo_comparado' => $this->periodPayload($periods['previous']),
                'datos_actualizados' => $this->lastSnapshotDate()?->toDateTimeString() ?? $this->lastUpdated()?->toDateTimeString(),
                'kpis' => $current,
                'comparativa' => $comparison,
                'insights' => $executiveInsights['insights'],
                'executive_insights' => $executiveInsights['insights'],
                'executive_insights_source' => $executiveInsights['source'],
                'filters' => $this->filterOptions($currentRows),
            ],
            'commercial_zones' => $commercialZones,
            'commercial_delegations' => $commercialDelegations,
            'commercials' => $commercials,
            'delegations' => $delegations,
            'portals' => $portals,
        ];
    }

    private function eachDecoratedLead(array $filters, array $period, callable $callback): void
    {
        $referenceDate = CarbonImmutable::parse($period['end']);

        $this->baseQuery($period)->chunkById(1000, function (Collection $rows) use ($filters, $callback, $referenceDate): void {
            foreach ($rows as $row) {
                $lead = $this->decorateLead($row, $row, $referenceDate);

                if ($this->passesFilters($lead, $filters)) {
                    $callback($lead);
                }
            }
        }, 'salesforce_leads.id', 'id');
    }

    private function decoratedLeadsForPeriod(array $period): array
    {
        return Cache::remember(
            'lead-dashboard-period-rows-v5:'.md5(json_encode([
                'period' => $this->periodPayload($period),
                'version' => $this->dataVersion(),
            ])),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($period): array {
                $rows = [];
                $referenceDate = CarbonImmutable::parse($period['end']);

                $this->baseQuery($period)->chunkById(1000, function (Collection $chunk) use (&$rows, $referenceDate): void {
                    foreach ($chunk as $row) {
                        $rows[] = $this->decorateLead($row, $row, $referenceDate);
                    }
                }, 'salesforce_leads.id', 'id');

                return $rows;
            }
        );
    }

    private function baseQuery(array $period): Builder
    {
        return SalesforceLead::query()
            ->leftJoin('salesforce_lead_activity_summaries as summaries', 'summaries.lead_salesforce_id', '=', 'salesforce_leads.salesforce_id')
            ->where('salesforce_leads.created_date', '>=', $period['start'])
            ->where('salesforce_leads.created_date', '<=', $period['end'])
            ->orderBy('salesforce_leads.id')
            ->select([
                'salesforce_leads.*',
                'summaries.total_actividades',
                'summaries.fecha_ultima_actividad',
            ]);
    }

    private function passesFilters(array $lead, array $filters): bool
    {
        if ($filters['portal'] && $lead['portal'] !== $filters['portal']) {
            return false;
        }

        if ($filters['lead_delegation'] && $lead['lead_delegation'] !== $filters['lead_delegation']) {
            return false;
        }

        if (! $this->passesLeadTypeFilter($lead['lead_type'], $filters['lead_type'])) {
            return false;
        }

        if ($filters['commercial_delegation'] && $lead['commercial_delegation'] !== $filters['commercial_delegation']) {
            return false;
        }

        if ($filters['zone'] && $lead['commercial_zone'] !== $filters['zone']) {
            return false;
        }

        if ($filters['commercial'] && $lead['gestor_id'] !== $filters['commercial']) {
            return false;
        }

        if ($filters['exposition_mode'] === 'without' && $lead['is_exposicion']) {
            return false;
        }

        return true;
    }

    private function passesLeadTypeFilter(?string $recordTypeName, ?string $filter): bool
    {
        if (blank($filter) || $filter === 'all') {
            return true;
        }

        if ($filter === 'Venta') {
            return in_array($recordTypeName, ['Venta', 'Venta con cambio'], true);
        }

        return $recordTypeName === $filter;
    }

    private function zoneFieldForContext(array $filters): string
    {
        if (($filters['context'] ?? 'summary') === 'commercials') {
            return 'commercial_zone';
        }

        if (in_array(($filters['context'] ?? 'summary'), ['summary', 'portals'], true) && filled($filters['commercial_delegation'])) {
            // En resumen y portales la zona representa lead_zone; si se fuerza una delegación comercial,
            // la zona acompaña ese mismo eje para evitar mezclar dos criterios de atribución.
            return 'commercial_zone';
        }

        return 'lead_zone';
    }

    private function addGroup(array &$groups, string $key, string $label, array $extra, array $lead): void
    {
        $groups[$key] ??= [
            'label' => $label,
            'extra' => $extra,
            'bucket' => $this->emptyBucket(),
        ];

        $this->addToBucket($groups[$key]['bucket'], $lead);
    }

    private function finalizeGroups(array $groups, string $labelKey): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $rows[] = array_merge($group['extra'], $this->finalizeBucket($group['bucket']), [
                $labelKey => $group['label'],
                'nombre' => $group['label'],
                'comercial' => $group['label'],
                'delegacion' => $group['label'],
            ]);
        }

        usort($rows, fn (array $a, array $b) => ($b['leads_totales'] ?? 0) <=> ($a['leads_totales'] ?? 0));

        return array_values($rows);
    }

    private function resolveChannel(mixed $medioNuevo): string
    {
        return $this->normalizeComparable((string) $medioNuevo) === $this->normalizeComparable('Llamada')
            ? 'Llamada'
            : 'Formulario';
    }

    private function resolvePortal(mixed $lead, string $channel): string
    {
        if ($channel === 'Llamada') {
            return $this->clean(data_get($lead, 'fuente_nuevo'))
                ?? $this->clean(data_get($lead, 'portal_text'))
                ?? $this->clean(data_get($lead, 'fuente_origen'))
                ?? 'Sin clasificar';
        }

        return $this->clean(data_get($lead, 'portal_text'))
            ?? $this->clean(data_get($lead, 'fuente_origen'))
            ?? $this->clean(data_get($lead, 'fuente_nuevo'))
            ?? 'Sin clasificar';
    }

    private function resolveLeadDelegation(mixed $lead): array
    {
        $raw = $this->clean(data_get($lead, 'delegacion_encargada_text'))
            ?? $this->clean(data_get($lead, 'delegacion_encargada'))
            ?? $this->clean(data_get($lead, 'delegacion_encargada_bueno'));

        return $this->delegationNormalizer->normalize($raw);
    }

    private function normalizeCommercialDelegation(mixed $raw): array
    {
        $normalized = $this->delegationNormalizer->normalize($this->clean($raw));

        if (Str::endsWith($normalized['delegation'], ' General')) {
            return [
                'raw' => $normalized['raw'],
                'delegation' => LeadDelegationNormalizer::UNCLASSIFIED,
                'group' => LeadDelegationNormalizer::NO_GROUP,
                'zone' => LeadDelegationNormalizer::UNCLASSIFIED,
                'is_classified' => false,
                'raw_unmapped' => $normalized['raw'],
            ];
        }

        return $normalized;
    }

    private function resolveSimplifiedManager(mixed $lead, bool $isConverted, bool $isDiscarded): array
    {
        $fields = $isConverted
            ? [['persona_que_trabajo_id', 'persona_que_trabajo_name'], ['owner_id', 'owner_name']]
            : ($isDiscarded
                ? [['propietario_descarte_id', 'propietario_descarte_name'], ['persona_que_trabajo_id', 'persona_que_trabajo_name'], ['owner_id', 'owner_name']]
                : [['owner_id', 'owner_name']]);

        foreach ($fields as [$idField, $nameField]) {
            $id = $this->clean(data_get($lead, $idField));

            if ($id) {
                return ['id' => $id, 'name' => $this->clean(data_get($lead, $nameField)) ?? $id];
            }
        }

        return ['id' => null, 'name' => 'Sin comercial'];
    }

    private function addToBucket(array &$bucket, array $lead): void
    {
        if (! self::INCLUDE_UNCLASSIFIED_IN_TOTALS && $lead['lead_delegation'] === 'Sin clasificar') {
            return;
        }

        $bucket['leads_totales']++;
        $bucket['convertidos'] += $lead['is_convertido'] ? 1 : 0;
        $bucket['descartados'] += $lead['is_descartado'] ? 1 : 0;
        $bucket['potenciales'] += $lead['is_potencial'] ? 1 : 0;
        $bucket['potenciales_sin_trabajar'] += $lead['is_potencial_sin_trabajar'] ? 1 : 0;
        $bucket['gestionados'] += $lead['is_gestionado'] ? 1 : 0;
        $bucket['llamadas'] += $lead['is_llamada'] ? 1 : 0;
        $bucket['formularios'] += $lead['is_formulario'] ? 1 : 0;
    }

    private function finalizeBucket(array $bucket): array
    {
        $total = $bucket['leads_totales'];

        return array_merge($bucket, [
            'conversion_pct' => $this->percentage($bucket['convertidos'], $total),
            'descarte_pct' => $this->percentage($bucket['descartados'], $total),
            'gestionados_pct' => $this->percentage($bucket['gestionados'], $total),
            'llamadas_pct' => $this->percentage($bucket['llamadas'], $total),
            'formularios_pct' => $this->percentage($bucket['formularios'], $total),
        ]);
    }

    private function emptyBucket(): array
    {
        return [
            'leads_totales' => 0,
            'convertidos' => 0,
            'descartados' => 0,
            'potenciales' => 0,
            'potenciales_sin_trabajar' => 0,
            'gestionados' => 0,
            'llamadas' => 0,
            'formularios' => 0,
        ];
    }

    private function compactComparison(array $current, array $previous): array
    {
        $metrics = [
            ['key' => 'leads_totales', 'label' => 'Leads totales'],
            ['key' => 'convertidos', 'label' => 'Convertidos', 'percent_key' => 'conversion_pct'],
            ['key' => 'descartados', 'label' => 'Descartados', 'percent_key' => 'descarte_pct'],
            ['key' => 'potenciales', 'label' => 'Potenciales'],
            ['key' => 'potenciales_sin_trabajar', 'label' => 'Potenciales sin trabajar'],
            ['key' => 'gestionados', 'label' => 'Gestionados', 'percent_key' => 'gestionados_pct'],
            ['key' => 'llamadas', 'label' => 'Llamadas', 'percent_key' => 'llamadas_pct'],
            ['key' => 'formularios', 'label' => 'Formularios', 'percent_key' => 'formularios_pct'],
        ];

        return array_map(function (array $metric) use ($current, $previous) {
            $currentValue = $current[$metric['key']] ?? null;
            $previousValue = $previous[$metric['key']] ?? null;
            $percentKey = $metric['percent_key'] ?? null;
            $currentPercent = $percentKey ? ($current[$percentKey] ?? null) : null;
            $previousPercent = $percentKey ? ($previous[$percentKey] ?? null) : null;

            return [
                'key' => $metric['key'],
                'metrica' => $metric['label'],
                'periodo_actual' => $currentValue,
                'periodo_actual_pct' => $currentPercent,
                'periodo_comparado' => $previousValue,
                'periodo_comparado_pct' => $previousPercent,
                'diferencia' => is_numeric($currentValue) && is_numeric($previousValue) ? round($currentValue - $previousValue, 2) : null,
                'diferencia_pct_puntos' => is_numeric($currentPercent) && is_numeric($previousPercent) ? round($currentPercent - $previousPercent, 2) : null,
                'variacion_pct' => $percentKey
                    ? null
                    : (is_numeric($currentValue) && is_numeric($previousValue) && (float) $previousValue !== 0.0
                        ? round((($currentValue - $previousValue) / $previousValue) * 100, 2)
                        : null),
                'is_compact' => $percentKey !== null,
                'is_percentage' => false,
            ];
        }, $metrics);
    }

    private function aiPayload(array $filters, array $periods, array $current, array $previous, array $comparison, array $portals, array $commercials, array $delegations): array
    {
        return [
            'periodo_actual' => $this->periodPayload($periods['current']),
            'periodo_comparado' => $this->periodPayload($periods['previous']),
            'filtros' => [
                'tipo_lead' => $filters['lead_type'] ?: 'all',
                'delegacion_lead' => $filters['lead_delegation'] ?: null,
                'delegacion_comercial' => $filters['commercial_delegation'] ?: null,
                'zona' => $filters['zone'] ?: null,
                'portal' => $filters['portal'] ?: null,
                'comercial' => $filters['commercial'] ?: null,
                'exposicion' => $filters['exposition_mode'] === 'without' ? 'excluir' : 'incluir',
            ],
            'kpis' => collect($current)->only([
                'leads_totales',
                'convertidos',
                'conversion_pct',
                'descartados',
                'descarte_pct',
                'potenciales',
                'potenciales_sin_trabajar',
                'gestionados',
                'gestionados_pct',
            ])->all(),
            'comparativa' => [
                'conversion_delta_pp' => $this->deltaFromComparison($comparison, 'convertidos'),
                'descarte_delta_pp' => $this->deltaFromComparison($comparison, 'descartados'),
                'gestionados_delta_pp' => $this->deltaFromComparison($comparison, 'gestionados'),
                'potenciales_sin_trabajar_delta' => $this->deltaFromComparison($comparison, 'potenciales_sin_trabajar', false),
            ],
            'rankings' => [
                'comerciales_pendientes' => collect($commercials)
                    ->sortByDesc('potenciales_sin_trabajar')
                    ->take(5)
                    ->map(fn (array $row) => collect($row)->only(['comercial', 'leads_totales', 'potenciales_sin_trabajar', 'gestionados_pct'])->all())
                    ->values()
                    ->all(),
                'delegaciones_descartes' => collect($delegations)
                    ->sortByDesc('descartados')
                    ->take(5)
                    ->map(fn (array $row) => collect($row)->only(['lead_delegation', 'leads_totales', 'descartados', 'descarte_pct', 'potenciales_sin_trabajar'])->all())
                    ->values()
                    ->all(),
                'portales_baja_conversion' => collect($portals)
                    ->filter(fn (array $row) => ($row['leads_totales'] ?? 0) > 0)
                    ->sortBy([
                        ['conversion_pct', 'asc'],
                        ['leads_totales', 'desc'],
                    ])
                    ->take(5)
                    ->map(fn (array $row) => collect($row)->only(['portal', 'leads_totales', 'convertidos', 'conversion_pct', 'descartados', 'descarte_pct'])->all())
                    ->values()
                    ->all(),
            ],
            'cache_version' => Cache::get('lead_dashboard_cache_version', 1),
        ];
    }

    private function deltaFromComparison(array $comparison, string $key, bool $percent = true): float|int|null
    {
        $row = collect($comparison)->firstWhere('key', $key);

        return $percent
            ? data_get($row, 'diferencia_pct_puntos')
            : data_get($row, 'diferencia');
    }

    private function comparison(array $current, array $previous): array
    {
        $metrics = [
            ['key' => 'leads_totales', 'label' => 'Leads totales', 'ratio' => false],
            ['key' => 'convertidos', 'label' => 'Convertidos', 'ratio' => false],
            ['key' => 'conversion_pct', 'label' => '% conversión', 'ratio' => true],
            ['key' => 'descartados', 'label' => 'Descartados', 'ratio' => false],
            ['key' => 'descarte_pct', 'label' => '% descarte', 'ratio' => true],
            ['key' => 'potenciales', 'label' => 'Potenciales', 'ratio' => false],
            ['key' => 'potenciales_sin_trabajar', 'label' => 'Potenciales sin trabajar', 'ratio' => false],
            ['key' => 'gestionados', 'label' => 'Gestionados', 'ratio' => false],
            ['key' => 'gestionados_pct', 'label' => '% gestionados', 'ratio' => true],
            ['key' => 'llamadas', 'label' => 'Llamadas', 'ratio' => false],
            ['key' => 'formularios', 'label' => 'Formularios', 'ratio' => false],
            ['key' => 'llamadas_pct', 'label' => '% llamadas', 'ratio' => true],
            ['key' => 'formularios_pct', 'label' => '% formularios', 'ratio' => true],
        ];

        return array_map(function (array $metric) use ($current, $previous) {
            $currentValue = $current[$metric['key']] ?? null;
            $previousValue = $previous[$metric['key']] ?? null;

            return [
                'key' => $metric['key'],
                'metrica' => $metric['label'],
                'periodo_actual' => $currentValue,
                'periodo_comparado' => $previousValue,
                'diferencia' => is_numeric($currentValue) && is_numeric($previousValue) ? round($currentValue - $previousValue, 2) : null,
                'variacion_pct' => is_numeric($currentValue) && is_numeric($previousValue) && (float) $previousValue !== 0.0
                    ? round((($currentValue - $previousValue) / $previousValue) * 100, 2)
                    : null,
                'is_percentage' => $metric['ratio'],
            ];
        }, $metrics);
    }

    private function insights(array $current, array $previous, array $portals, array $commercials, array $delegations): array
    {
        if ($current['leads_totales'] === 0) {
            return ['No hay datos suficientes para generar conclusiones del periodo actual.'];
        }

        $insights = [
            'Hay '.$current['potenciales_sin_trabajar'].' potenciales sin trabajar en el periodo actual.',
        ];

        $conversionDiff = round(($current['conversion_pct'] ?? 0) - ($previous['conversion_pct'] ?? 0), 2);
        $insights[] = 'La conversión '.($conversionDiff >= 0 ? 'sube ' : 'baja ').abs($conversionDiff).' puntos frente al periodo comparado.';

        $discardDiff = round(($current['descarte_pct'] ?? 0) - ($previous['descarte_pct'] ?? 0), 2);
        $insights[] = 'El descarte '.($discardDiff >= 0 ? 'sube ' : 'baja ').abs($discardDiff).' puntos frente al periodo comparado.';

        if (! empty($portals[0])) {
            $insights[] = 'El portal '.$portals[0]['portal'].' concentra el mayor volumen de leads.';
        }

        $delegationsByPending = $delegations;
        usort($delegationsByPending, fn (array $a, array $b) => ($b['potenciales_sin_trabajar'] ?? 0) <=> ($a['potenciales_sin_trabajar'] ?? 0));
        if (! empty($delegationsByPending[0])) {
            $insights[] = 'La delegación '.$delegationsByPending[0]['lead_delegation'].' acumula más potenciales sin trabajar.';
        }

        $commercialsByPending = $commercials;
        usort($commercialsByPending, fn (array $a, array $b) => ($b['potenciales_sin_trabajar'] ?? 0) <=> ($a['potenciales_sin_trabajar'] ?? 0));
        if (! empty($commercialsByPending[0])) {
            $insights[] = 'El comercial '.$commercialsByPending[0]['comercial'].' acumula más potenciales sin trabajar.';
        }

        return array_slice($insights, 0, 6);
    }

    private function actionableInsights(array $current, array $previous, array $portals, array $commercials, array $delegations): array
    {
        if ($current['leads_totales'] === 0) {
            return ['No hay datos suficientes para generar conclusiones del periodo actual.'];
        }

        $insights = [];

        if ($current['potenciales_sin_trabajar'] > 0) {
            $insights[] = 'Hay '.$current['potenciales_sin_trabajar'].' potenciales sin trabajar en el periodo seleccionado.';
        }

        $conversionDiff = round(($current['conversion_pct'] ?? 0) - ($previous['conversion_pct'] ?? 0), 2);
        if ($conversionDiff < 0) {
            $insights[] = 'La conversion baja '.abs($conversionDiff).' puntos frente al periodo comparado.';
        }

        $discardDiff = round(($current['descarte_pct'] ?? 0) - ($previous['descarte_pct'] ?? 0), 2);
        if ($discardDiff > 0) {
            $insights[] = 'El descarte sube '.abs($discardDiff).' puntos frente al periodo comparado.';
        }

        $volumeThreshold = max(3, (int) ceil($current['leads_totales'] * 0.15));
        $weakPortal = collect($portals)
            ->filter(fn (array $portal) => ($portal['leads_totales'] ?? 0) >= $volumeThreshold
                && ($portal['conversion_pct'] ?? 0) < max(3, ($current['conversion_pct'] ?? 0) - 2))
            ->sortByDesc('leads_totales')
            ->first();
        if ($weakPortal) {
            $insights[] = 'El portal '.$weakPortal['portal'].' concentra alto volumen y baja conversion.';
        }

        $delegationsByPending = $delegations;
        usort($delegationsByPending, fn (array $a, array $b) => ($b['potenciales_sin_trabajar'] ?? 0) <=> ($a['potenciales_sin_trabajar'] ?? 0));
        if (! empty($delegationsByPending[0]) && ($delegationsByPending[0]['potenciales_sin_trabajar'] ?? 0) > 0) {
            $insights[] = 'La delegacion '.$delegationsByPending[0]['lead_delegation'].' acumula mas potenciales sin trabajar.';
        }

        $delegationsByDiscard = $delegations;
        usort($delegationsByDiscard, fn (array $a, array $b) => ($b['descartados'] ?? 0) <=> ($a['descartados'] ?? 0));
        if (! empty($delegationsByDiscard[0])
            && ($delegationsByDiscard[0]['descartados'] ?? 0) >= $volumeThreshold
            && ($delegationsByDiscard[0]['descarte_pct'] ?? 0) > max(30, ($current['descarte_pct'] ?? 0) + 5)) {
            $insights[] = 'La delegacion '.$delegationsByDiscard[0]['lead_delegation'].' concentra un nivel alto de descartes.';
        }

        $commercialsByPending = $commercials;
        usort($commercialsByPending, fn (array $a, array $b) => ($b['potenciales_sin_trabajar'] ?? 0) <=> ($a['potenciales_sin_trabajar'] ?? 0));
        if (! empty($commercialsByPending[0]) && (($commercialsByPending[0]['potenciales_sin_trabajar'] ?? 0) >= 3
            || (($commercialsByPending[0]['leads_totales'] ?? 0) >= $volumeThreshold && ($commercialsByPending[0]['gestionados_pct'] ?? 100) < 50))) {
            $insights[] = 'El comercial '.$commercialsByPending[0]['comercial'].' acumula muchos leads pendientes o baja gestion.';
        }

        return array_slice($insights ?: ['No se detectan alertas relevantes con los filtros actuales.'], 0, 6);
    }

    private function filterOptions(array $rows): array
    {
        $rows = collect($rows);

        $commercialDelegations = $this->commercialUsers()
            ->pluck('user_delegation')
            ->map(fn ($delegation) => $this->normalizeCommercialDelegation($delegation)['delegation'])
            ->filter()
            ->unique();

        $zones = collect($this->delegationNormalizer->knownZones())
            ->merge($rows->pluck('commercial_zone'))
            ->filter()
            ->reject(fn (string $zone) => $zone === LeadDelegationNormalizer::UNCLASSIFIED)
            ->unique();

        return [
            'commercials' => $this->commercialUsers()
                ->map(fn ($user, string $id) => ['id' => $id, 'name' => $user['name']])
                ->sortBy('name')
                ->values()
                ->all(),
            'portals' => $rows
                ->pluck('portal')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'lead_delegations' => $this->delegationNormalizer->sortLabels($rows->pluck('lead_delegation')->all()),
            'lead_types' => self::LEAD_TYPES,
            'commercial_delegations' => $this->delegationNormalizer->sortLabels($commercialDelegations->all()),
            'zones' => $this->delegationNormalizer->sortLabels($zones->all()),
        ];
    }

    private function cacheKey(array $filters, array $periods): string
    {
        return 'lead-dashboard-v6:'.md5(json_encode([
            'filters' => $filters,
            'periods' => [
                'current' => $this->periodPayload($periods['current']),
                'previous' => $this->periodPayload($periods['previous']),
            ],
            'version' => $this->dataVersion(),
        ]));
    }

    private function dataVersion(): array
    {
        return [
            'lead_count' => SalesforceLead::query()->count(),
            'user_count' => SalesforceUser::query()->count(),
            'summary_count' => SalesforceLeadActivitySummary::query()->count(),
            'lead_max_id' => SalesforceLead::query()->max('id'),
            'lead_min_salesforce_id' => SalesforceLead::query()->min('salesforce_id'),
            'lead_max_salesforce_id' => SalesforceLead::query()->max('salesforce_id'),
            'user_max_id' => SalesforceUser::query()->max('id'),
            'dashboard_cache_version' => Cache::get('lead_dashboard_cache_version', 1),
            'leads' => SalesforceLead::query()->max('updated_at'),
            'users' => SalesforceUser::query()->max('updated_at'),
            'summaries' => SalesforceLeadActivitySummary::query()->max('updated_at'),
            'snapshot' => MonthlyCommercialReportSnapshot::query()->max('generated_at'),
        ];
    }

    private function periodPayload(array $period): array
    {
        return [
            'inicio' => CarbonImmutable::parse($period['start'])->toDateString(),
            'fin' => CarbonImmutable::parse($period['end'])->toDateString(),
        ];
    }

    private function lastSnapshotDate(): ?CarbonImmutable
    {
        $generatedAt = MonthlyCommercialReportSnapshot::query()->max('generated_at');

        return $generatedAt ? CarbonImmutable::parse($generatedAt) : null;
    }

    private function lastUpdated(): ?CarbonImmutable
    {
        $updated = SalesforceLead::query()->max('updated_at');

        return $updated ? CarbonImmutable::parse($updated) : null;
    }

    private function percentage(int|float $value, int|float $total): ?float
    {
        return $total > 0 ? round(($value / $total) * 100, 2) : null;
    }

    private function commercialUsers(): Collection
    {
        if ($this->commercialUsersCache !== null) {
            return $this->commercialUsersCache;
        }

        return $this->commercialUsersCache = SalesforceUser::query()
            ->where('is_active', true)
            ->whereIn('profile_name', self::COMMERCIAL_PROFILES)
            ->get()
            ->keyBy('salesforce_id')
            ->map(fn (SalesforceUser $user) => [
                'name' => $user->name,
                'profile_name' => $user->profile_name,
                'user_delegation' => $user->user_delegation,
            ]);
    }

    private function portalMap(): Collection
    {
        return $this->portalMapCache ??= MasterPortal::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (MasterPortal $portal) => [$this->normalizeComparable($portal->portal_original) => $portal->portal_group]);
    }

    private function portalGroup(string $portal): string
    {
        return $this->portalMap()->get($this->normalizeComparable($portal)) ?? $portal ?: 'Sin clasificar';
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

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeComparable(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replace(['hr motor ', '.', ',', '-', '_', '/'], [''])
            ->replaceMatches('/\s+/', '')
            ->toString();
    }
}
