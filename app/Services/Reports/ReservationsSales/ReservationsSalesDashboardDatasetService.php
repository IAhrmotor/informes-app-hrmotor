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
            'reservas-ventas-dashboard-v3:'.md5(json_encode([
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
        $comparison = $this->comparison($current['bucket'], $previous['bucket']);
        $aiPayload = $this->aiPayload($filters, $periods, $current['bucket'], $comparison, $current);
        $insights = $this->aiInsights->generate($aiPayload);

        return [
            'summary' => [
                'ok' => $current['bucket']['oportunidades_totales'] > 0 || $previous['bucket']['oportunidades_totales'] > 0,
                'message' => $current['bucket']['oportunidades_totales'] > 0 ? null : 'No hay oportunidades sincronizadas para el periodo seleccionado.',
                'periodo_actual' => $this->periodPayload($periods['current']),
                'periodo_comparado' => $this->periodPayload($periods['previous']),
                'datos_actualizados' => $this->lastUpdated()?->toDateTimeString(),
                'kpis' => $current['bucket'],
                'comparativa' => $comparison,
                'executive_insights' => $insights['insights'],
                'executive_insights_source' => $insights['source'],
                'insights' => $insights['insights'],
                'filters' => $this->filterOptions(),
            ],
            'commercial_zones' => $current['zones'],
            'commercial_delegations' => $current['delegations'],
            'commercials' => $current['commercials'],
            'portals' => $current['portals'],
        ];
    }

    private function aggregate(array $filters, array $period): array
    {
        $bucket = $this->emptyBucket();
        $zones = [];
        $delegations = [];
        $commercials = [];
        $portals = [];

        $this->baseQuery($filters, $period)
            ->orderBy('id')
            ->chunkById(1000, function (Collection $rows) use (&$bucket, &$zones, &$delegations, &$commercials, &$portals): void {
                foreach ($rows as $opportunity) {
                    $row = $this->decorate($opportunity);
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
        ];
    }

    private function baseQuery(array $filters, array $period)
    {
        $query = SalesforceOpportunity::query();
        $field = $this->dateField($filters['date_criterion']);

        $query->where($field, '>=', $period['start'])
            ->where($field, '<=', $period['end']);

        if (in_array($filters['opportunity_type'], ['Tasacion', 'Tasación'], true)) {
            $query->where('record_type_name', 'Tasacion');
        } elseif ($filters['opportunity_type'] === 'Venta') {
            $query->whereIn('record_type_name', ['Venta', 'Cambio']);
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
            $currentEnd = $now->subMonthNoOverflow()->endOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $currentStart->subMonthNoOverflow()->startOfMonth(), 'end' => $currentStart->subMonthNoOverflow()->endOfMonth()],
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

    private function dateField(string $criterion): string
    {
        return match ($criterion) {
            'reservation_date' => 'reservation_date',
            'cv_signed_date' => 'cv_signed_date',
            default => 'created_date',
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

    private function periodPayload(array $period): array
    {
        return [
            'inicio' => CarbonImmutable::parse($period['start'])->toDateString(),
            'fin' => CarbonImmutable::parse($period['end'])->toDateString(),
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
