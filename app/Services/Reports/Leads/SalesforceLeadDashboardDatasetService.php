<?php

namespace App\Services\Reports\Leads;

use App\Models\MasterCallDelegationMapping;
use App\Models\MasterDelegation;
use App\Models\MasterFormSenderMapping;
use App\Models\MasterPortal;
use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SalesforceLeadDashboardDatasetService
{
    private const COMMERCIAL_PROFILES = [
        'Compra/Venta',
        'Comerciales Partner Community',
    ];

    private ?Collection $commercialUsersCache = null;
    private ?Collection $portalMapCache = null;
    private ?Collection $callDelegationMapCache = null;
    private ?Collection $formSenderMapCache = null;
    private ?Collection $delegationMapCache = null;

    public function summary(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);
        $current = $this->aggregate($filters, $periods['current']);
        $previous = $this->aggregate($filters, $periods['previous']);
        $portals = $this->portalRows($request);
        $commercials = $this->commercialRows($request);
        $delegations = $this->delegationRows($request);

        return [
            'ok' => $current['leads_totales'] > 0 || $previous['leads_totales'] > 0,
            'message' => $current['leads_totales'] > 0
                ? null
                : 'No hay datos sincronizados para el periodo seleccionado.',
            'periodo_actual' => $this->periodPayload($periods['current']),
            'periodo_comparado' => $this->periodPayload($periods['previous']),
            'datos_actualizados' => $this->lastUpdated()?->toDateTimeString(),
            'kpis' => $current,
            'comparativa' => $this->comparison($current, $previous),
            'insights' => $this->insights($current, $previous, $portals['items'], $commercials['items'], $delegations['items']),
            'filters' => $this->filterOptions(),
        ];
    }

    public function commercialRows(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);
        $rows = $this->groupedRows($filters, $periods['current'], function (array $lead): ?array {
            if (! $lead['gestor_es_comercial']) {
                return null;
            }

            return [
                'key' => $lead['gestor_id'],
                'label' => $lead['gestor_nombre'] ?: 'Sin comercial',
                'extra' => [],
            ];
        });

        return ['items' => $rows];
    }

    public function delegationRows(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);
        $rows = $this->groupedRows($filters, $periods['current'], fn (array $lead) => [
            'key' => $lead['delegacion'].'|'.$lead['grupo_comercial'],
            'label' => $lead['delegacion'],
            'extra' => ['grupo_comercial' => $lead['grupo_comercial']],
        ]);

        return ['items' => $rows];
    }

    public function portalRows(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);
        $rows = $this->groupedRows($filters, $periods['current'], fn (array $lead) => [
            'key' => $lead['portal'].'|'.$lead['grupo_portal'],
            'label' => $lead['portal'],
            'extra' => ['grupo_portal' => $lead['grupo_portal']],
        ]);

        return ['items' => $rows];
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
            'portal_group' => $request->string('portal_group')->toString(),
            'channel' => $request->string('channel')->toString(),
            'delegation' => $request->string('delegation')->toString(),
            'commercial_group' => $request->string('commercial_group')->toString(),
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
            $currentStart = $this->parseDate($filters['current_start'], $now->subDays(30)->startOfDay());
            $currentEnd = $this->parseDate($filters['current_end'], $now)->endOfDay();
            $comparisonStart = $this->parseDate($filters['comparison_start'], $currentStart->subDays($currentStart->diffInDays($currentEnd) + 1))->startOfDay();
            $comparisonEnd = $this->parseDate($filters['comparison_end'], $currentStart->subDay())->endOfDay();

            return [
                'current' => ['start' => $currentStart->startOfDay(), 'end' => $currentEnd],
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
        $portalGroup = $this->portalGroup($portal);
        $delegation = $this->resolveDelegation($lead, $channel, $portal);
        $manager = $this->resolveSimplifiedManager($lead, $isConverted, $isDiscarded);
        $commercialUser = $manager['id'] ? $this->commercialUsers()->get($manager['id']) : null;

        $totalActivities = (int) (data_get($summary, 'total_actividades') ?? 0);
        $lastActivity = data_get($summary, 'fecha_ultima_actividad');
        $lastActivityAt = $lastActivity ? CarbonImmutable::parse($lastActivity) : null;
        $hasRecentActivity = $lastActivityAt !== null && $lastActivityAt->greaterThanOrEqualTo($referenceDate->subDays(3));
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
            'grupo_portal' => $portalGroup,
            'delegacion' => $delegation['delegation'],
            'grupo_comercial' => $delegation['commercial_group'],
            'gestor_id' => $manager['id'],
            'gestor_nombre' => data_get($commercialUser, 'name') ?? $manager['name'],
            'gestor_es_comercial' => $commercialUser !== null,
            'is_exposicion' => Str::lower($portal) === Str::lower('Exposición') || Str::lower($portalGroup) === Str::lower('Exposición'),
            'total_actividades' => $totalActivities,
            'fecha_ultima_actividad' => $lastActivityAt,
        ];
    }

    private function aggregate(array $filters, array $period): array
    {
        $bucket = $this->emptyBucket();

        $this->eachDecoratedLead($filters, $period, function (array $lead) use (&$bucket): void {
            $this->addToBucket($bucket, $lead);
        });

        return $this->finalizeBucket($bucket);
    }

    private function groupedRows(array $filters, array $period, callable $groupResolver): array
    {
        $groups = [];

        $this->eachDecoratedLead($filters, $period, function (array $lead) use (&$groups, $groupResolver): void {
            $group = $groupResolver($lead);

            if ($group === null) {
                return;
            }

            $key = $group['key'];
            $groups[$key] ??= array_merge([
                'label' => $group['label'],
                'extra' => $group['extra'] ?? [],
                'bucket' => $this->emptyBucket(),
            ]);

            $this->addToBucket($groups[$key]['bucket'], $lead);
        });

        $rows = [];

        foreach ($groups as $group) {
            $metrics = $this->finalizeBucket($group['bucket']);
            $rows[] = array_merge($group['extra'], $metrics, [
                'nombre' => $group['label'],
                'comercial' => $group['label'],
                'delegacion' => $group['label'],
                'portal' => $group['label'],
            ]);
        }

        usort($rows, fn (array $a, array $b) => ($b['leads_totales'] ?? 0) <=> ($a['leads_totales'] ?? 0));

        return array_values($rows);
    }

    private function eachDecoratedLead(array $filters, array $period, callable $callback): void
    {
        $query = $this->baseQuery($period);
        $referenceDate = CarbonImmutable::parse($period['end']);

        $query->chunkById(1000, function (Collection $rows) use ($filters, $callback, $referenceDate): void {
            foreach ($rows as $row) {
                $lead = $this->decorateLead($row, $row, $referenceDate);

                if ($this->passesFilters($lead, $filters)) {
                    $callback($lead);
                }
            }
        }, 'salesforce_leads.id', 'id');
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

        if ($filters['portal_group'] && $lead['grupo_portal'] !== $filters['portal_group']) {
            return false;
        }

        if ($filters['channel'] && $lead['canal'] !== $filters['channel']) {
            return false;
        }

        if ($filters['delegation'] && $lead['delegacion'] !== $filters['delegation']) {
            return false;
        }

        if ($filters['commercial_group'] && $lead['grupo_comercial'] !== $filters['commercial_group']) {
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

        if ($filters['exposition_mode'] === 'only' && ! $lead['is_exposicion']) {
            return false;
        }

        return true;
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

    private function portalGroup(string $portal): string
    {
        return $this->portalMap()->get($this->normalizeComparable($portal)) ?? $portal ?: 'Sin clasificar';
    }

    private function resolveDelegation(mixed $lead, string $channel, string $portal): array
    {
        if ($channel === 'Llamada') {
            $received = $this->clean(data_get($lead, 'delegacion_encargada_text'));
            $mapping = $received ? $this->callDelegationMap()->get($this->normalizeComparable($portal).'|'.$this->normalizeComparable($received)) : null;

            return $this->delegationResult($mapping, $received);
        }

        $sender = $this->clean(data_get($lead, 'remitente_lead'));

        if ($sender) {
            $mapping = $this->formSenderMap()->get($this->normalizeComparable($portal).'|'.mb_strtolower($sender));

            if ($mapping) {
                return $this->delegationResult($mapping, $sender);
            }
        }

        $fallback = $this->clean(data_get($lead, 'delegacion_encargada_bueno'))
            ?? $this->clean(data_get($lead, 'delegacion_encargada'))
            ?? $this->clean(data_get($lead, 'delegacion_original'))
            ?? $this->clean(data_get($lead, 'delegacion_encargada_text'));

        return $this->delegationResult(null, $fallback);
    }

    private function delegationResult(mixed $mapping, ?string $fallback): array
    {
        if ($mapping) {
            return [
                'delegation' => $mapping['delegation_name'] ?: ($mapping['commercial_group'] ?: 'Sin clasificar'),
                'commercial_group' => $mapping['commercial_group'] ?: 'Sin clasificar',
            ];
        }

        if ($fallback) {
            $master = $this->delegationMap()->get($this->normalizeComparable($fallback));

            if ($master) {
                return [
                    'delegation' => $master['delegation_name'],
                    'commercial_group' => $master['commercial_group'],
                ];
            }
        }

        return [
            'delegation' => 'Sin clasificar',
            'commercial_group' => 'Sin clasificar',
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

    private function comparison(array $current, array $previous): array
    {
        $metrics = [
            ['key' => 'leads_totales', 'label' => 'Leads totales', 'ratio' => false],
            ['key' => 'convertidos', 'label' => 'Convertidos', 'ratio' => false],
            ['key' => 'conversion_pct', 'label' => '% conversion', 'ratio' => true],
            ['key' => 'descartados', 'label' => 'Descartados', 'ratio' => false],
            ['key' => 'descarte_pct', 'label' => '% descarte', 'ratio' => true],
            ['key' => 'potenciales', 'label' => 'Potenciales', 'ratio' => false],
            ['key' => 'potenciales_sin_trabajar', 'label' => 'Potenciales sin trabajar', 'ratio' => false],
            ['key' => 'gestionados', 'label' => 'Gestionados', 'ratio' => false],
            ['key' => 'gestionados_pct', 'label' => '% gestionados', 'ratio' => true],
            ['key' => 'llamadas', 'label' => 'Llamadas', 'ratio' => false],
            ['key' => 'formularios', 'label' => 'Formularios', 'ratio' => false],
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

        $insights = [];
        $insights[] = 'Hay '.$current['potenciales_sin_trabajar'].' potenciales sin trabajar en el periodo actual.';

        $conversionDiff = round(($current['conversion_pct'] ?? 0) - ($previous['conversion_pct'] ?? 0), 2);
        $insights[] = 'La conversion '.($conversionDiff >= 0 ? 'sube ' : 'baja ').abs($conversionDiff).' puntos frente al periodo comparado.';

        $discardDiff = round(($current['descarte_pct'] ?? 0) - ($previous['descarte_pct'] ?? 0), 2);
        $insights[] = 'El descarte '.($discardDiff >= 0 ? 'sube ' : 'baja ').abs($discardDiff).' puntos frente al periodo comparado.';

        if (! empty($portals[0])) {
            $insights[] = 'El portal '.$portals[0]['portal'].' concentra el mayor volumen de leads.';
        }

        if (! empty($delegations[0])) {
            $insights[] = 'La delegacion/zona '.$delegations[0]['delegacion'].' acumula mas potenciales sin trabajar.';
        }

        if (! empty($commercials[0])) {
            $insights[] = 'El comercial '.$commercials[0]['comercial'].' concentra mas potenciales sin trabajar.';
        }

        return array_slice($insights, 0, 6);
    }

    private function addToBucket(array &$bucket, array $lead): void
    {
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

    private function filterOptions(): array
    {
        return [
            'commercials' => $this->commercialUsers()->map(fn ($user, string $id) => ['id' => $id, 'name' => $user['name']])->values()->all(),
            'portals' => SalesforceLead::query()->select('portal_text')->whereNotNull('portal_text')->distinct()->orderBy('portal_text')->pluck('portal_text')->values()->all(),
            'portal_groups' => MasterPortal::query()->where('is_active', true)->orderBy('portal_group')->distinct()->pluck('portal_group')->values()->all(),
            'delegations' => MasterDelegation::query()->where('is_active', true)->orderBy('delegation_name')->pluck('delegation_name')->values()->all(),
            'commercial_groups' => MasterDelegation::query()->where('is_active', true)->orderBy('commercial_group')->distinct()->pluck('commercial_group')->values()->all(),
            'statuses' => ['Convertido', 'Descartado', 'Potencial'],
        ];
    }

    private function periodPayload(array $period): array
    {
        return [
            'inicio' => CarbonImmutable::parse($period['start'])->toDateString(),
            'fin' => CarbonImmutable::parse($period['end'])->toDateString(),
        ];
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
            ]);
    }

    private function portalMap(): Collection
    {
        return $this->portalMapCache ??= MasterPortal::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (MasterPortal $portal) => [$this->normalizeComparable($portal->portal_original) => $portal->portal_group]);
    }

    private function callDelegationMap(): Collection
    {
        return $this->callDelegationMapCache ??= MasterCallDelegationMapping::query()
            ->where('status', 'active')
            ->get()
            ->mapWithKeys(fn (MasterCallDelegationMapping $mapping) => [
                $this->normalizeComparable($mapping->portal_original).'|'.$this->normalizeComparable($mapping->received_value) => [
                    'delegation_name' => $mapping->delegation_name,
                    'commercial_group' => $mapping->commercial_group,
                ],
            ]);
    }

    private function formSenderMap(): Collection
    {
        return $this->formSenderMapCache ??= MasterFormSenderMapping::query()
            ->where('status', 'active')
            ->get()
            ->mapWithKeys(fn (MasterFormSenderMapping $mapping) => [
                $this->normalizeComparable($mapping->portal_original).'|'.mb_strtolower($mapping->sender_email) => [
                    'delegation_name' => $mapping->delegation_name,
                    'commercial_group' => $mapping->commercial_group,
                ],
            ]);
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
