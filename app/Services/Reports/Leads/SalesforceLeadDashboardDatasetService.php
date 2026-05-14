<?php

namespace App\Services\Reports\Leads;

use App\Models\MasterDelegation;
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

    // Dirección quiere que los no clasificados cuenten en KPIs generales por ahora.
    private const INCLUDE_UNCLASSIFIED_IN_TOTALS = true;

    private ?Collection $commercialUsersCache = null;
    private ?Collection $portalMapCache = null;
    private ?Collection $delegationMapCache = null;

    public function payload(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return Cache::remember(
            $this->cacheKey($filters, $periods),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildPayload($filters, $periods)
        );
    }

    public function summary(Request $request): array
    {
        return $this->payload($request)['summary'];
    }

    public function commercialRows(Request $request): array
    {
        return ['items' => $this->payload($request)['commercials']];
    }

    public function delegationRows(Request $request): array
    {
        return ['items' => $this->payload($request)['delegations']];
    }

    public function portalRows(Request $request): array
    {
        return ['items' => $this->payload($request)['portals']];
    }

    public function filters(Request $request): array
    {
        return [
            'period' => $request->string('period')->toString() ?: 'last_30_days',
            'current_start' => $request->string('current_start')->toString(),
            'current_end' => $request->string('current_end')->toString(),
            'comparison_start' => $request->string('comparison_start')->toString(),
            'comparison_end' => $request->string('comparison_end')->toString(),
            'portal' => $request->string('portal')->toString(),
            'channel' => $request->string('channel')->toString(),
            'lead_delegation' => $request->string('lead_delegation')->toString()
                ?: $request->string('delegation')->toString(),
            'commercial_delegation' => $request->string('commercial_delegation')->toString(),
            'zone' => $request->string('zone')->toString(),
            'commercial' => $request->string('commercial')->toString(),
            'status' => $request->string('status')->toString(),
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
        $commercialDelegation = $this->normalizeDelegation(data_get($commercialUser, 'user_delegation'));

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
            'lead_zone' => $leadDelegation['zone'],
            'commercial_delegation' => $commercialDelegation['delegation'],
            'commercial_zone' => $commercialDelegation['zone'],
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
                $this->addGroup($commercialGroups, $lead['gestor_id'], $lead['gestor_nombre'], [
                    'commercial_delegation' => $lead['commercial_delegation'],
                    'zone' => $lead['commercial_zone'],
                ], $lead);
            }

            $this->addGroup($delegationGroups, $lead['lead_delegation'].'|'.$lead['lead_zone'], $lead['lead_delegation'], [
                'zone' => $lead['lead_zone'],
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
        $commercials = $this->finalizeGroups($commercialGroups, 'comercial');
        $delegations = $this->finalizeGroups($delegationGroups, 'lead_delegation');
        $portals = $this->finalizeGroups($portalGroups, 'portal');

        return [
            'summary' => [
                'ok' => $current['leads_totales'] > 0 || $previous['leads_totales'] > 0,
                'message' => $current['leads_totales'] > 0 ? null : 'No hay datos sincronizados para el periodo seleccionado.',
                'periodo_actual' => $this->periodPayload($periods['current']),
                'periodo_comparado' => $this->periodPayload($periods['previous']),
                'datos_actualizados' => $this->lastSnapshotDate()?->toDateTimeString() ?? $this->lastUpdated()?->toDateTimeString(),
                'kpis' => $current,
                'comparativa' => $this->comparison($current, $previous),
                'insights' => $this->insights($current, $previous, $portals, $commercials, $delegations),
                'filters' => $this->filterOptions($currentRows),
            ],
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
            'lead-dashboard-period-rows-v2:'.md5(json_encode([
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

        if ($filters['channel'] && $lead['canal'] !== $filters['channel']) {
            return false;
        }

        if ($filters['lead_delegation'] && $lead['lead_delegation'] !== $filters['lead_delegation']) {
            return false;
        }

        if ($filters['commercial_delegation'] && $lead['commercial_delegation'] !== $filters['commercial_delegation']) {
            return false;
        }

        // En fase 1 el filtro Zona se aplica sobre la delegación comercial.
        if ($filters['zone'] && $lead['commercial_zone'] !== $filters['zone']) {
            return false;
        }

        if ($filters['commercial'] && $lead['gestor_id'] !== $filters['commercial']) {
            return false;
        }

        if ($filters['status'] && $lead['status'] !== $filters['status']) {
            return false;
        }

        if ($filters['exposition_mode'] === 'without' && $lead['is_exposicion']) {
            return false;
        }

        return true;
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

        return $this->normalizeDelegation($raw);
    }

    private function normalizeDelegation(?string $raw): array
    {
        if (! $raw) {
            return ['delegation' => 'Sin clasificar', 'zone' => 'Sin clasificar', 'raw' => null];
        }

        $master = $this->delegationMap()->get($this->normalizeComparable($raw));

        if ($master) {
            return [
                'delegation' => $master['delegation_name'],
                'zone' => $master['commercial_group'] ?: 'Sin clasificar',
                'raw' => $raw,
            ];
        }

        return [
            'delegation' => $raw,
            'zone' => 'Sin clasificar',
            'raw' => $raw,
        ];
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

    private function filterOptions(array $rows): array
    {
        $rows = collect($rows);

        $commercialDelegations = $this->commercialUsers()
            ->pluck('user_delegation')
            ->map(fn ($delegation) => $this->normalizeDelegation($delegation)['delegation'])
            ->filter()
            ->unique();

        $zones = $this->commercialUsers()
            ->pluck('user_delegation')
            ->map(fn ($delegation) => $this->normalizeDelegation($delegation)['zone'])
            ->merge(
                MasterDelegation::query()
                    ->where('is_active', true)
                    ->pluck('commercial_group')
            )
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

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
            'lead_delegations' => $rows
                ->pluck('lead_delegation')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'commercial_delegations' => $commercialDelegations
                ->sort()
                ->values()
                ->all(),
            'zones' => $zones,
            'statuses' => ['Convertido', 'Descartado', 'Potencial'],
        ];
    }

    private function cacheKey(array $filters, array $periods): string
    {
        return 'lead-dashboard-v3:'.md5(json_encode([
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

    private function delegationMap(): Collection
    {
        if ($this->delegationMapCache !== null) {
            return $this->delegationMapCache;
        }

        $map = collect();

        MasterDelegation::query()
            ->where('is_active', true)
            ->get()
            ->each(function (MasterDelegation $delegation) use ($map): void {
                $payload = [
                    'delegation_name' => $delegation->delegation_name,
                    'commercial_group' => $delegation->commercial_group,
                ];

                $map->put($this->normalizeComparable($delegation->delegation_name), $payload);
                $map->put($this->normalizeComparable(str_replace('HR MOTOR ', '', $delegation->delegation_name)), $payload);
                $map->put($this->normalizeComparable($delegation->commercial_group), $payload);
            });

        return $this->delegationMapCache = $map;
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
