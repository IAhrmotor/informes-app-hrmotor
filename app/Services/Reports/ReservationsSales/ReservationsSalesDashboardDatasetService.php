<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReservationsSalesDashboardDatasetService
{
    private const CACHE_TTL_MINUTES = 10;
    private const OPPORTUNITY_TYPES = ['Tasación', 'Venta'];

    public function __construct(
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly OpportunityPortalNormalizer $portalNormalizer,
        private readonly ReservationsSalesAiInsightsService $aiInsights,
    ) {
    }

    public function summary(Request $request): array
    {
        return $this->payload($request)['summary'];
    }

    public function kpiAudit(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);
        $metric = $this->resolveAuditMetric($request->string('metric')->toString());

        return Cache::remember(
            'reservas-ventas-dashboard-audit-v1:'.md5(json_encode([
                'filters' => $filters,
                'period' => $this->periodPayload($periods['current']),
                'metric' => $metric,
                'version' => $this->dataVersion(),
            ])),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildAuditPayload($filters, $periods['current'], $metric)
        );
    }

    public function commercialRows(Request $request): array
    {
        $payload = $this->payload($request);

        return [
            'ok' => true,
            'zones' => $payload['commercial_zones'],
            'delegations' => $payload['commercial_delegations'],
            'commercials' => $payload['commercials'],
            'items' => $payload['commercials'],
        ];
    }

    public function portalRows(Request $request): array
    {
        return ['items' => $this->payload($request)['portals']];
    }

    public function payload(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return Cache::remember(
            'reservas-ventas-dashboard-v4:'.md5(json_encode([
                'filters' => $filters,
                'periods' => $this->periodPayloads($periods),
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
        $current['bucket']['reservas_vivas_actuales_salesforce'] = $this->globalLiveReservations($filters);
        $comparison = $this->comparison($current['bucket'], $previous['bucket']);
        $aiPayload = $this->aiPayload($filters, $periods, $current['bucket'], $comparison, $current);
        $insights = $this->aiInsights->generate($aiPayload);

        return [
            'summary' => [
                'ok' => $current['bucket']['oportunidades_totales'] > 0
                    || $previous['bucket']['oportunidades_totales'] > 0
                    || $current['bucket']['reservas_vivas_actuales_salesforce'] > 0,
                'message' => (
                    $current['bucket']['oportunidades_totales'] > 0
                    || $current['bucket']['reservas_vivas_actuales_salesforce'] > 0
                ) ? null : 'No hay oportunidades sincronizadas para el periodo seleccionado.',
                'periodo_actual' => $this->periodPayload($periods['current']),
                'periodo_comparado' => $this->periodPayload($periods['previous']),
                'datos_actualizados' => $this->lastUpdated()?->toDateTimeString(),
                'kpis' => $current['bucket'],
                'comparativa' => $comparison,
                'executive_insights' => $insights['insights'],
                'executive_insights_source' => $insights['source'],
                'insights' => $insights['insights'],
                'filters' => $current['filters'],
            ],
            'commercial_zones' => $current['zones'],
            'commercial_delegations' => $current['delegations'],
            'commercials' => $current['commercials'],
            'portals' => $current['portals'],
        ];
    }

    private function buildAuditPayload(array $filters, array $period, string $metric): array
    {
        $items = [];

        $this->auditQuery($filters, $period, $metric)
            ->orderBy('id')
            ->chunkById(1000, function (Collection $rows) use (&$items, $filters, $metric): void {
                foreach ($rows as $opportunity) {
                    $row = $this->decorate($opportunity);

                    if (! $this->passesFilters($row, $filters)) {
                        continue;
                    }

                    $items[] = $this->decorateAuditOpportunity($opportunity, $metric, $filters['date_criterion']);
                }
            });

        usort($items, fn (array $a, array $b) => [$a['metric_date'] ?? '', $a['opportunity_id'] ?? ''] <=> [$b['metric_date'] ?? '', $b['opportunity_id'] ?? '']);

        return [
            'ok' => true,
            'metric' => $metric,
            'metric_label' => $this->auditMetricLabel($metric),
            'periodo_actual' => $this->periodPayload($period),
            'total' => count($items),
            'items' => $items,
        ];
    }

    private function aggregate(array $filters, array $period): array
    {
        $bucket = $this->emptyBucket();
        $zones = [];
        $delegations = [];
        $commercials = [];
        $portals = [];
        $filterOptions = $this->emptyFilterOptionsAccumulator();

        $this->baseQuery($filters, $period)
            ->orderBy('id')
            ->chunkById(1000, function (Collection $rows) use (&$bucket, &$zones, &$delegations, &$commercials, &$portals, &$filterOptions, $filters): void {
                foreach ($rows as $opportunity) {
                    $row = $this->decorate($opportunity);

                    $this->collectFilterOptions($filterOptions, $row);

                    if (! $this->passesFilters($row, $filters)) {
                        continue;
                    }

                    $this->addToBucket($bucket, $row);
                    $this->addGroup($zones, $row['zone'], $row['zone'], [], $row);
                    $this->addGroup($delegations, $row['commercial_delegation'].'|'.$row['zone'], $row['commercial_delegation'], ['zone' => $row['zone']], $row);
                    $this->addGroup($commercials, $row['owner_id'], $row['owner_name'] ?: $row['owner_id'], [
                        'commercial_delegation' => $row['commercial_delegation'],
                        'zone' => $row['zone'],
                    ], $row);
                    $this->addGroup($portals, $row['portal'], $row['portal'], [], $row);
                }
            });

        return [
            'bucket' => $this->finalizeBucket($bucket),
            'zones' => $this->finalizeGroups($zones, 'zone'),
            'delegations' => $this->finalizeGroups($delegations, 'commercial_delegation'),
            'commercials' => $this->finalizeGroups($commercials, 'comercial'),
            'portals' => $this->finalizeGroups($portals, 'portal'),
            'filters' => $this->filterOptionsFromAccumulator($filterOptions),
        ];
    }

    private function baseQuery(array $filters, array $period)
    {
        $query = SalesforceOpportunity::query();
        $field = $this->dateField($filters['date_criterion']);

        $query->where($field, '>=', $period['start'])
            ->where($field, '<', $period['end']);

        $this->applyOpportunityTypeFilter($query, $filters['opportunity_type']);

        return $query;
    }

    private function auditQuery(array $filters, array $period, string $metric)
    {
        if ($metric === 'reservas_vivas_actuales_salesforce') {
            return $this->globalLiveReservationsQuery($filters);
        }

        return match ($metric) {
            'reservas_vivas' => $this->baseQuery($filters, $period)
                ->where('reservation', true)
                ->where('cv_signed', false)
                ->whereRaw("LOWER(COALESCE(stage_name, '')) <> 'cerrada perdida'"),
            'oportunidades_caidas' => $this->baseQuery($filters, $period)
                ->whereRaw("LOWER(COALESCE(stage_name, '')) = 'cerrada perdida'"),
            'cv_firmados' => $this->baseQuery($filters, $period)
                ->where('cv_signed', true)
                ->whereRaw("LOWER(COALESCE(stage_name, '')) <> 'cerrada perdida'"),
            default => $this->baseQuery($filters, $period),
        };
    }

    private function globalLiveReservations(array $filters): int
    {
        return $this->globalLiveReservationsQuery($filters)->count();
    }

    private function globalLiveReservationsQuery(array $filters)
    {
        $query = SalesforceOpportunity::query()
            ->where('reservation', true)
            ->where('cv_signed', false)
            ->whereRaw("LOWER(COALESCE(stage_name, '')) <> 'cerrada perdida'");

        $this->applyOpportunityTypeFilter($query, $filters['opportunity_type']);

        if ($this->hasOperationalFilters($filters)) {
            $ids = [];

            $query->orderBy('id')->chunkById(1000, function (Collection $rows) use (&$ids, $filters): void {
                foreach ($rows as $opportunity) {
                    $row = $this->decorate($opportunity);

                    if ($this->passesFilters($row, $filters)) {
                        $ids[] = $opportunity->getKey();
                    }
                }
            });

            return SalesforceOpportunity::query()->whereIn('id', $ids ?: [0]);
        }

        return $query;
    }

    private function decorate(SalesforceOpportunity $opportunity): array
    {
        $delegation = $this->normalizeCommercialDelegation($opportunity->owner_delegation);
        $stage = (string) $opportunity->stage_name;
        $isClosedLost = strcasecmp($stage, 'Cerrada Perdida') === 0;
        $reservation = (bool) $opportunity->reservation;
        $cvSigned = (bool) $opportunity->cv_signed;
        $portal = $this->portalNormalizer->normalize($opportunity->portal_resolved);

        return [
            'owner_id' => $opportunity->owner_id,
            'owner_name' => $opportunity->owner_name,
            'commercial_delegation' => $delegation['delegation'],
            'zone' => $delegation['zone'],
            'portal' => $portal['is_valid_final'] ? $portal['portal'] : OpportunityPortalNormalizer::UNCLASSIFIED,
            'is_reserva_viva' => $reservation && ! $cvSigned && ! $isClosedLost,
            'is_caida' => $isClosedLost,
            'is_cv_firmado' => $cvSigned && ! $isClosedLost,
        ];
    }

    private function decorateAuditOpportunity(SalesforceOpportunity $opportunity, string $metric, string $dateCriterion): array
    {
        $row = $this->decorate($opportunity);

        return [
            'metric' => $metric,
            'metric_label' => $this->auditMetricLabel($metric),
            'metric_date' => $this->auditMetricDate($opportunity, $metric, $dateCriterion),
            'opportunity_id' => $opportunity->salesforce_id,
            'opportunity_name' => $opportunity->name,
            'created_date' => $this->auditDate($opportunity->created_date),
            'close_date' => $this->auditDate($opportunity->close_date),
            'reservation_date' => $this->auditDate($opportunity->reservation_date),
            'cv_signed_date' => $this->auditDate($opportunity->cv_signed_date),
            'record_type_name' => $opportunity->record_type_name,
            'stage_name' => $opportunity->stage_name,
            'owner_id' => $opportunity->owner_id,
            'owner_name' => $opportunity->owner_name,
            'commercial_delegation' => $row['commercial_delegation'],
            'zone' => $row['zone'],
            'account_id' => $opportunity->account_id,
            'account_name' => $opportunity->account_name,
            'account_phone' => $opportunity->account_phone,
            'account_person_email' => $opportunity->account_person_email,
            'account_company_email' => $opportunity->account_company_email,
            'portal_original' => $opportunity->portal_original,
            'portal_resolved' => $opportunity->portal_resolved,
            'portal_resolution_source' => $opportunity->portal_resolution_source,
            'portal_resolution_lead_id' => $opportunity->portal_resolution_lead_id,
            'opportunity_source_raw' => $opportunity->opportunity_source_raw,
            'opportunity_source_normalized' => $opportunity->opportunity_source_normalized,
            'is_reserva_viva' => $row['is_reserva_viva'],
            'is_caida' => $row['is_caida'],
            'is_cv_firmado' => $row['is_cv_firmado'],
        ];
    }

    private function filters(Request $request): array
    {
        return [
            'period' => $request->string('period')->toString() ?: 'last_30_days',
            'date_criterion' => $request->string('date_criterion')->toString() ?: 'created_date',
            'current_start' => $request->string('current_start')->toString(),
            'current_end' => $request->string('current_end')->toString(),
            'comparison_start' => $request->string('comparison_start')->toString(),
            'comparison_end' => $request->string('comparison_end')->toString(),
            'opportunity_type' => $request->string('opportunity_type')->toString() ?: 'all',
            'commercial_delegation' => $request->string('commercial_delegation')->toString(),
            'zone' => $request->string('zone')->toString(),
            'commercial' => $request->string('commercial')->toString(),
        ];
    }

    private function passesFilters(array $row, array $filters): bool
    {
        if ($filters['commercial_delegation'] && $row['commercial_delegation'] !== $filters['commercial_delegation']) {
            return false;
        }

        if ($filters['zone'] && $row['zone'] !== $filters['zone']) {
            return false;
        }

        if ($filters['commercial'] && $row['owner_id'] !== $filters['commercial']) {
            return false;
        }

        return true;
    }

    private function periods(array $filters): array
    {
        $now = CarbonImmutable::now();

        if ($filters['period'] === 'custom') {
            $currentStart = $this->parseDate($filters['current_start'], $now->subDays(30))->startOfDay();
            $currentEnd = $this->parseDate($filters['current_end'], $now)->addDay()->startOfDay();
            $comparisonStart = $this->parseDate($filters['comparison_start'], $currentStart->subDays((int) floor($currentStart->diffInDays($currentEnd->subDay())) + 1))->startOfDay();
            $comparisonEnd = $this->parseDate($filters['comparison_end'], $currentStart->subDay())->addDay()->startOfDay();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $comparisonStart, 'end' => $comparisonEnd],
            ];
        }

        if ($filters['period'] === 'current_month') {
            $currentStart = $now->startOfMonth();
            $currentEnd = $now;
            $previousStart = $currentStart->subMonthNoOverflow();
            $previousEnd = $previousStart->addDays((int) floor($currentStart->diffInDays($currentEnd)))->endOfDay();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $previousStart, 'end' => $previousEnd],
            ];
        }

        if ($filters['period'] === 'previous_month') {
            $currentStart = $now->subMonthNoOverflow()->startOfMonth();
            $currentEnd = $now->startOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $currentStart->subMonthNoOverflow()->startOfMonth(), 'end' => $currentStart],
            ];
        }

        return [
            'current' => ['start' => $now->subDays(30), 'end' => $now],
            'previous' => ['start' => $now->subDays(60), 'end' => $now->subDays(30)],
        ];
    }

    private function addToBucket(array &$bucket, array $row): void
    {
        $bucket['oportunidades_totales']++;
        $bucket['reservas_vivas'] += $row['is_reserva_viva'] ? 1 : 0;
        $bucket['oportunidades_caidas'] += $row['is_caida'] ? 1 : 0;
        $bucket['cv_firmados'] += $row['is_cv_firmado'] ? 1 : 0;
    }

    private function addGroup(array &$groups, string $key, string $label, array $extra, array $row): void
    {
        $groups[$key] ??= ['label' => $label, 'extra' => $extra, 'bucket' => $this->emptyBucket()];
        $this->addToBucket($groups[$key]['bucket'], $row);
    }

    private function finalizeGroups(array $groups, string $labelKey): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $rows[] = array_merge($group['extra'], $group['bucket'], [
                $labelKey => $group['label'],
                'nombre' => $group['label'],
                'comercial' => $group['label'],
                'delegacion' => $group['label'],
            ]);
        }

        $rows = $this->applyColumnPercentages($rows);

        usort($rows, fn (array $a, array $b) => ($b['oportunidades_totales'] ?? 0) <=> ($a['oportunidades_totales'] ?? 0));

        return array_values($rows);
    }

    private function applyColumnPercentages(array $rows): array
    {
        $totals = [
            'reservas_vivas' => array_sum(array_column($rows, 'reservas_vivas')),
            'oportunidades_caidas' => array_sum(array_column($rows, 'oportunidades_caidas')),
            'cv_firmados' => array_sum(array_column($rows, 'cv_firmados')),
        ];

        return array_map(fn (array $row) => array_merge($row, [
            'reservas_vivas_pct' => $this->columnPercentage($row['reservas_vivas'], $totals['reservas_vivas']),
            'oportunidades_caidas_pct' => $this->columnPercentage($row['oportunidades_caidas'], $totals['oportunidades_caidas']),
            'cv_firmados_pct' => $this->columnPercentage($row['cv_firmados'], $totals['cv_firmados']),
        ]), $rows);
    }

    private function finalizeBucket(array $bucket): array
    {
        $total = $bucket['oportunidades_totales'];

        return array_merge($bucket, [
            'reservas_vivas_pct' => $this->percentage($bucket['reservas_vivas'], $total),
            'oportunidades_caidas_pct' => $this->percentage($bucket['oportunidades_caidas'], $total),
            'cv_firmados_pct' => $this->percentage($bucket['cv_firmados'], $total),
        ]);
    }

    private function emptyBucket(): array
    {
        return [
            'oportunidades_totales' => 0,
            'reservas_vivas' => 0,
            'oportunidades_caidas' => 0,
            'cv_firmados' => 0,
        ];
    }

    private function comparison(array $current, array $previous): array
    {
        return collect([
            ['key' => 'oportunidades_totales', 'label' => 'Oportunidades totales'],
            ['key' => 'reservas_vivas', 'label' => 'Reservas vivas', 'percent_key' => 'reservas_vivas_pct'],
            ['key' => 'oportunidades_caidas', 'label' => 'Oportunidades caídas', 'percent_key' => 'oportunidades_caidas_pct'],
            ['key' => 'cv_firmados', 'label' => 'Contratos CV firmados', 'percent_key' => 'cv_firmados_pct'],
        ])->map(function (array $metric) use ($current, $previous) {
            $percentKey = $metric['percent_key'] ?? null;

            return [
                'key' => $metric['key'],
                'metrica' => $metric['label'],
                'periodo_actual' => $current[$metric['key']] ?? null,
                'periodo_actual_pct' => $percentKey ? ($current[$percentKey] ?? null) : null,
                'periodo_comparado' => $previous[$metric['key']] ?? null,
                'periodo_comparado_pct' => $percentKey ? ($previous[$percentKey] ?? null) : null,
                'diferencia' => ($current[$metric['key']] ?? 0) - ($previous[$metric['key']] ?? 0),
                'diferencia_pct_puntos' => $percentKey ? round(($current[$percentKey] ?? 0) - ($previous[$percentKey] ?? 0), 2) : null,
                'is_compact' => $percentKey !== null,
            ];
        })->all();
    }

    private function aiPayload(array $filters, array $periods, array $bucket, array $comparison, array $groups): array
    {
        return [
            'periodo_actual' => $this->periodPayload($periods['current']),
            'periodo_comparado' => $this->periodPayload($periods['previous']),
            'filtros' => [
                'tipo_oportunidad' => $filters['opportunity_type'],
                'criterio_fecha' => $filters['date_criterion'],
            ],
            'kpis' => $bucket,
            'comparativa' => [
                'reservas_delta_pp' => data_get(collect($comparison)->firstWhere('key', 'reservas_vivas'), 'diferencia_pct_puntos'),
                'caidas_delta_pp' => data_get(collect($comparison)->firstWhere('key', 'oportunidades_caidas'), 'diferencia_pct_puntos'),
                'cv_firmados_delta_pp' => data_get(collect($comparison)->firstWhere('key', 'cv_firmados'), 'diferencia_pct_puntos'),
            ],
            'rankings' => [
                'comerciales_caidas' => collect($groups['commercials'])->sortByDesc('oportunidades_caidas')->take(5)->values()->all(),
                'delegaciones_descartes' => collect($groups['delegations'])->sortByDesc('oportunidades_caidas')->take(5)->values()->all(),
                'portales_baja_conversion' => collect($groups['portals'])->sortBy('cv_firmados_pct')->take(5)->values()->all(),
            ],
        ];
    }

    private function filterOptions(): array
    {
        return [
            'opportunity_types' => self::OPPORTUNITY_TYPES,
        ];
    }

    private function emptyFilterOptionsAccumulator(): array
    {
        return [
            'commercials' => [],
            'commercial_delegations' => [],
            'zones' => [],
        ];
    }

    private function collectFilterOptions(array &$options, array $row): void
    {
        if (filled($row['owner_id'] ?? null) || filled($row['owner_name'] ?? null)) {
            $options['commercials'][(string) ($row['owner_id'] ?? $row['owner_name'])] = [
                'id' => (string) ($row['owner_id'] ?? ''),
                'name' => (string) ($row['owner_name'] ?: $row['owner_id']),
            ];
        }

        if (filled($row['commercial_delegation'] ?? null)) {
            $options['commercial_delegations'][$row['commercial_delegation']] = true;
        }

        if (filled($row['zone'] ?? null) && $row['zone'] !== LeadDelegationNormalizer::UNCLASSIFIED) {
            $options['zones'][$row['zone']] = true;
        }
    }

    private function filterOptionsFromAccumulator(array $options): array
    {
        return [
            'opportunity_types' => self::OPPORTUNITY_TYPES,
            'commercials' => collect($options['commercials'])
                ->values()
                ->filter(fn (array $item) => $item['id'] !== '' || $item['name'] !== '')
                ->sortBy('name')
                ->values()
                ->all(),
            'commercial_delegations' => $this->delegationNormalizer->sortLabels(array_keys($options['commercial_delegations'])),
            'zones' => $this->delegationNormalizer->sortLabels(
                collect($this->delegationNormalizer->knownZones())
                    ->merge(array_keys($options['zones']))
                    ->unique()
                    ->values()
                    ->all()
            ),
        ];
    }

    private function hasOperationalFilters(array $filters): bool
    {
        return filled($filters['commercial_delegation'])
            || filled($filters['zone'])
            || filled($filters['commercial']);
    }

    private function dateField(string $criterion): string
    {
        return match ($criterion) {
            'reservation_date' => 'reservation_date',
            'cv_signed_date' => 'cv_signed_date',
            default => 'created_date',
        };
    }

    private function resolveAuditMetric(?string $metric): string
    {
        $metric = trim((string) $metric);

        return in_array($metric, [
            'oportunidades_totales',
            'reservas_vivas',
            'reservas_vivas_actuales_salesforce',
            'oportunidades_caidas',
            'cv_firmados',
        ], true) ? $metric : 'oportunidades_totales';
    }

    private function auditMetricLabel(string $metric): string
    {
        return match ($metric) {
            'reservas_vivas' => 'Reservas vivas',
            'reservas_vivas_actuales_salesforce' => 'Reservas vivas actuales Salesforce',
            'oportunidades_caidas' => 'Oportunidades caidas',
            'cv_firmados' => 'Contratos CV firmados',
            default => 'Oportunidades totales',
        };
    }

    private function applyOpportunityTypeFilter($query, string $opportunityType): void
    {
        if (in_array($opportunityType, ['Tasacion', 'Tasación'], true)) {
            $query->where('record_type_name', 'Tasacion');

            return;
        }

        if ($opportunityType === 'Venta') {
            $query->whereIn('record_type_name', ['Venta', 'Cambio']);
        }
    }

    private function auditMetricDate(SalesforceOpportunity $opportunity, string $metric, string $dateCriterion): ?string
    {
        $criterionField = $this->dateField($dateCriterion);

        return match ($metric) {
            'reservas_vivas', 'reservas_vivas_actuales_salesforce' => $this->auditDate($opportunity->reservation_date)
                ?: $this->auditDate($opportunity->{$criterionField}),
            'oportunidades_caidas' => $this->auditDate($opportunity->close_date)
                ?: $this->auditDate($opportunity->{$criterionField}),
            'cv_firmados' => $this->auditDate($opportunity->cv_signed_date)
                ?: $this->auditDate($opportunity->{$criterionField}),
            default => $this->auditDate($opportunity->{$criterionField}),
        };
    }

    private function normalizeCommercialDelegation(?string $raw): array
    {
        $normalized = $this->delegationNormalizer->normalize($raw);

        if (str_ends_with($normalized['delegation'], ' General')) {
            return [
                'delegation' => LeadDelegationNormalizer::UNCLASSIFIED,
                'zone' => LeadDelegationNormalizer::UNCLASSIFIED,
            ];
        }

        return $normalized;
    }

    private function percentage(int|float $value, int|float $total): ?float
    {
        return $total > 0 ? round(($value / $total) * 100, 2) : null;
    }

    private function columnPercentage(int|float $value, int|float $total): float
    {
        return $total > 0 ? round(($value / $total) * 100, 2) : 0.0;
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

    private function auditDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function periodPayload(array $period): array
    {
        $end = CarbonImmutable::parse($period['end']);
        $displayEnd = $end->isStartOfDay() && $end->greaterThan(CarbonImmutable::parse($period['start']))
            ? $end->subDay()
            : $end;

        return [
            'inicio' => CarbonImmutable::parse($period['start'])->toDateString(),
            'fin' => $displayEnd->toDateString(),
        ];
    }

    private function periodPayloads(array $periods): array
    {
        return [
            'current' => $this->periodPayload($periods['current']),
            'previous' => $this->periodPayload($periods['previous']),
        ];
    }

    private function lastUpdated(): ?CarbonImmutable
    {
        $updated = SalesforceOpportunity::query()->max('updated_at');

        return $updated ? CarbonImmutable::parse($updated) : null;
    }

    private function dataVersion(): array
    {
        return [
            'count' => SalesforceOpportunity::query()->count(),
            'max_id' => SalesforceOpportunity::query()->max('id'),
            'updated_at' => SalesforceOpportunity::query()->max('updated_at'),
            'dashboard_cache_version' => Cache::get('reservas_ventas_dashboard_cache_version', 1),
        ];
    }
}
