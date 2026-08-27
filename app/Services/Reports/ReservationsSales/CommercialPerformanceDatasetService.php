<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\CommercialDelegationSnapshot;
use App\Models\CommercialPerformanceMonthlyTarget;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityHistorySyncInterval;
use App\Models\SalesforceOpportunityStageTransition;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Reports\MonthlyCommercial\MonthlyCommercialLeadEnricher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CommercialPerformanceDatasetService
{
    private const CACHE_TTL_MINUTES = 10;

    private const DATASET_TIMEZONE = 'Europe/Madrid';

    public function __construct(
        private readonly MonthlyCommercialLeadEnricher $leadEnricher,
        private readonly LeadRecordTypeNormalizer $recordTypeNormalizer,
        private readonly CommercialPerformanceMonthlyRosterService $monthlyRoster,
    ) {}

    public function payload(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        return Cache::remember(
            'reservas-ventas-commercial-performance-v1:'.hash('sha256', json_encode([
                'filters' => $filters,
                'version' => $this->dataVersion(),
            ])),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => $this->build($filters),
        );
    }

    public function updateTarget(string $month, int $target, ?int $reportUserId): CommercialPerformanceMonthlyTarget
    {
        $monthDate = CarbonImmutable::createFromFormat('!Y-m', $month, self::DATASET_TIMEZONE)->startOfMonth();

        return CommercialPerformanceMonthlyTarget::query()->updateOrCreate(
            ['month' => $monthDate->toDateString()],
            [
                'reservations_target' => $target,
                'is_explicit' => true,
                'updated_by_report_user_id' => $reportUserId,
            ],
        );
    }

    private function build(array $filters): array
    {
        $selected = CarbonImmutable::createFromFormat('!Y-m', $filters['month'], self::DATASET_TIMEZONE)->startOfMonth();
        $months = collect(range(3, 0))->map(fn (int $offset): CarbonImmutable => $selected->subMonthsNoOverflow($offset));
        $start = $months->first()->startOfMonth();
        $end = $selected->addMonth()->startOfMonth();
        $monthKeys = $months->map->format('Y-m')->all();
        $targets = $this->targets($months);
        $rosterContext = $this->monthlyRoster->context($months);
        $historyCoverage = $this->historyCoverage($months);
        $buckets = array_fill_keys($monthKeys, []);
        $quality = [
            'unresolved_attribution_events' => 0,
            'duplicate_conflict_groups' => 0,
            'uncertified_historical_events' => 0,
            'margin_conflict_groups' => 0,
            'invalid_cancellation_chronology' => 0,
            'organisation_changes_within_month' => collect($rosterContext['assessments'][$filters['month']] ?? [])
                ->where('reason', 'organisation_change_within_month')
                ->count(),
            'bootstrap_approved_assignments' => collect($rosterContext['assignments'][$filters['month']] ?? [])
                ->where('delegation_status', 'bootstrap_approved')
                ->count(),
            'observed_assignments' => collect($rosterContext['assignments'][$filters['month']] ?? [])
                ->where('delegation_status', 'observed')
                ->count(),
        ];

        $this->seedCertifiedRoster($buckets, $monthKeys, $rosterContext);
        $this->aggregateLeads($buckets, $quality, $start, $end, $rosterContext);
        $this->aggregateOpportunities($buckets, $quality, $start, $end, $rosterContext);
        $this->aggregateCancellations($buckets, $quality, $start, $end, $rosterContext);

        $rowsByMonth = [];
        foreach ($monthKeys as $monthKey) {
            $rowsByMonth[$monthKey] = collect($buckets[$monthKey])
                ->map(fn (array $bucket): array => $this->finalizeRow(
                    $bucket,
                    $targets[$monthKey]['value'],
                    $historyCoverage[$monthKey]['status'] === 'covered',
                ))
                ->values();
        }

        $currentRows = $rowsByMonth[$filters['month']];
        $filterOptions = $this->filterOptions($currentRows, $filters);
        $rankUniverse = $this->applyOrganisationFilters($currentRows, $filters);
        $ranked = $this->applyTeamComparisonsAndRanking($rankUniverse);
        $displayRows = filled($filters['commercial'])
            ? $ranked->where('commercial_id', $filters['commercial'])->values()
            : $ranked->values();

        $evolution = collect($monthKeys)->map(function (string $monthKey) use ($rowsByMonth, $filters, $historyCoverage): array {
            $rows = $this->applyOrganisationFilters($rowsByMonth[$monthKey], $filters);
            if (filled($filters['commercial'])) {
                $rows = $rows->where('commercial_id', $filters['commercial'])->values();
            }

            return $this->evolutionRow(
                $monthKey,
                $rows,
                $historyCoverage[$monthKey]['status'] === 'covered',
            );
        })->all();

        return [
            'ok' => true,
            'month' => $filters['month'],
            'objective' => [
                'reservations_target' => $targets[$filters['month']]['value'],
                'is_explicit' => (bool) $targets[$filters['month']]['is_explicit'],
                'default' => CommercialPerformanceMonthlyTarget::DEFAULT_RESERVATIONS_TARGET,
            ],
            'items' => $displayRows->all(),
            'evolution' => $evolution,
            'filters' => $filterOptions,
            'summary' => $this->evolutionRow(
                $filters['month'],
                $displayRows,
                $historyCoverage[$filters['month']]['status'] === 'covered',
            ),
            'data_quality' => $quality + [
                'cancellations_available' => $historyCoverage[$filters['month']]['status'] === 'covered',
                'cancellation_coverage_status' => $historyCoverage[$filters['month']]['status'],
                'cancellation_source_cutoff_at' => $historyCoverage[$filters['month']]['source_cutoff_at'],
                'cancellation_certified_until' => $historyCoverage[$filters['month']]['certified_until'],
                'cancellation_unresolved_dependencies' => $historyCoverage[$filters['month']]['unresolved_dependencies'],
                'cancellation_coverage_by_month' => $historyCoverage,
                'cancellation_source' => 'OpportunityHistory',
                'delegation_history_certified_from' => DB::table('commercial_delegation_snapshots')->min('observed_from'),
                'delegation_history_evaluable_from' => DB::table('commercial_delegation_snapshots')
                    ->whereIn('source', [
                        CommercialDelegationSnapshotService::SOURCE_OBSERVED,
                        CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP,
                    ])->min('observed_from'),
                'delegation_history_observed_from' => DB::table('commercial_delegation_snapshots')
                    ->where('source', CommercialDelegationSnapshotService::SOURCE_OBSERVED)
                    ->min('observed_from'),
                'delegation_history_bootstrap_from' => DB::table('commercial_delegation_snapshots')
                    ->where('source', CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP)
                    ->min('observed_from'),
                'delegation_history_limitation' => 'Desde 2026-04-01 se admite el bootstrap aprobado por negocio cuando la primera asignación fiable no tiene evidencias contradictorias; se distingue de la observación Salesforce.',
            ],
            'semantics' => [
                'activity_monthly' => true,
                'cohort' => false,
                'ratios_may_exceed_100' => true,
                'cancellation_date_field' => 'salesforce_opportunity_stage_transitions.transitioned_at',
            ],
            'dataset_source' => 'local_snapshot',
            'dataset_generated_at' => now()->toIso8601String(),
            'dataset_timezone' => self::DATASET_TIMEZONE,
        ];
    }

    private function aggregateLeads(
        array &$buckets,
        array &$quality,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $rosterContext,
    ): void {
        SalesforceLead::query()
            ->where('is_deleted', false)
            ->where('fecha_asignacion', '>=', $start->utc())
            ->where('fecha_asignacion', '<', $end->utc())
            ->select([
                'id', 'salesforce_id', 'status', 'record_type_name', 'record_type_normalized',
                'owner_id', 'owner_name', 'persona_que_trabajo_id', 'persona_que_trabajo_name',
                'propietario_descarte_id', 'propietario_descarte_name', 'fecha_asignacion',
            ])
            ->orderBy('id')
            ->chunkById(1000, function ($leads) use (&$buckets, &$quality, $rosterContext): void {
                foreach ($leads as $lead) {
                    $type = $lead->record_type_normalized ?: $this->recordTypeNormalizer->normalize($lead->record_type_name);
                    if (! in_array($type, $this->recordTypeNormalizer->ventaFilterTypes(), true)) {
                        continue;
                    }

                    $responsible = $this->leadEnricher->effectiveResponsible($lead);
                    $userId = $responsible['id'] ?? null;
                    $attribution = $this->attribution(
                        $userId,
                        $responsible['name'] ?? data_get($rosterContext['users']->get($userId), 'name'),
                        $lead->fecha_asignacion,
                        $rosterContext,
                        $quality,
                    );
                    $this->increment($buckets, $this->monthKey($lead->fecha_asignacion), $attribution, 'leads');
                }
            });
    }

    private function aggregateOpportunities(
        array &$buckets,
        array &$quality,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $rosterContext,
    ): void {
        $reservationGroups = [];
        $activeReservationGroups = [];
        $saleGroups = [];

        SalesforceOpportunity::query()
            ->whereIn('record_type_name', ['Venta', 'Cambio'])
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($created) use ($start, $end): void {
                    $created->where('created_date', '>=', $start->utc())
                        ->where('created_date', '<', $end->utc());
                })->orWhere(function ($reservation) use ($start, $end): void {
                    $reservation->where('reservation_date', '>=', $start->toDateString())
                        ->where('reservation_date', '<', $end->toDateString());
                })->orWhere(function ($sale) use ($start, $end): void {
                    $sale->where('cv_signed_date', '>=', $start->toDateString())
                        ->where('cv_signed_date', '<', $end->toDateString());
                });
            })
            ->select([
                'id', 'salesforce_id', 'created_date', 'reservation', 'reservation_date',
                'cv_signed', 'cv_signed_date', 'stage_name', 'owner_id', 'owner_name',
                'vehicle_interest_id', 'vehicle_plate', 'informe_rentabilidad',
            ])
            ->orderBy('id')
            ->chunkById(1000, function ($opportunities) use (
                &$buckets, &$quality, &$reservationGroups, &$activeReservationGroups, &$saleGroups, $start, $end, $rosterContext
            ): void {
                foreach ($opportunities as $opportunity) {
                    if ($this->inRange($opportunity->created_date, $start, $end)) {
                        $attribution = $this->attribution(
                            $opportunity->owner_id,
                            $opportunity->owner_name,
                            $opportunity->created_date,
                            $rosterContext,
                            $quality,
                        );
                        $this->increment($buckets, $this->monthKey($opportunity->created_date), $attribution, 'opportunities');
                    }

                    if ($opportunity->reservation && $this->inRange($opportunity->reservation_date, $start, $end)) {
                        $event = $this->opportunityEvent($opportunity, $opportunity->reservation_date, $rosterContext, $quality);
                        $key = $this->eventKey($opportunity, $opportunity->reservation_date);
                        $reservationGroups[$key][] = $event;
                        if (! $opportunity->cv_signed && strcasecmp((string) $opportunity->stage_name, 'Cerrada Perdida') !== 0) {
                            $activeReservationGroups[$key][] = $event;
                        }
                    }

                    if ($opportunity->cv_signed
                        && strcasecmp((string) $opportunity->stage_name, 'Cerrada Perdida') !== 0
                        && $this->inRange($opportunity->cv_signed_date, $start, $end)) {
                        $event = $this->opportunityEvent($opportunity, $opportunity->cv_signed_date, $rosterContext, $quality);
                        $event['margin'] = $opportunity->informe_rentabilidad === null
                            ? null
                            : (float) $opportunity->informe_rentabilidad;
                        $saleGroups[$this->eventKey($opportunity, $opportunity->cv_signed_date)][] = $event;
                    }
                }
            });

        $this->applyDeduplicatedGroups($buckets, $quality, $reservationGroups, 'reservations_total');
        $this->applyDeduplicatedGroups($buckets, $quality, $activeReservationGroups, 'reservations_active');
        $this->applyDeduplicatedGroups($buckets, $quality, $saleGroups, 'sales');
    }

    private function aggregateCancellations(
        array &$buckets,
        array &$quality,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $rosterContext,
    ): void {
        $transitions = SalesforceOpportunityStageTransition::query()
            ->where('transitioned_at', '>=', $start->utc())
            ->where('transitioned_at', '<', $end->utc())
            ->where('is_reservation_cancellation', true)
            ->whereRaw("LOWER(new_stage) = 'cerrada perdida'")
            ->get();
        $opportunities = SalesforceOpportunity::query()
            ->whereIn('salesforce_id', $transitions->pluck('opportunity_salesforce_id')->unique())
            ->get(['salesforce_id', 'vehicle_interest_id', 'vehicle_plate'])
            ->keyBy('salesforce_id');
        $groups = [];

        foreach ($transitions as $transition) {
            $opportunity = $opportunities->get($transition->opportunity_salesforce_id);
            $identity = $this->vehicleIdentity($opportunity)
                ?: 'opportunity:'.$transition->opportunity_salesforce_id;
            $key = $identity.'|'.$transition->transitioned_at->format('Y-m-d');
            $groups[$key][] = [
                'month' => $this->monthKey($transition->transitioned_at),
                'attribution' => $this->attribution(
                    $transition->owner_id,
                    $transition->owner_name,
                    $transition->transitioned_at,
                    $rosterContext,
                    $quality,
                ),
            ];
        }

        $quality['invalid_cancellation_chronology'] += SalesforceOpportunityStageTransition::query()
            ->where('transitioned_at', '>=', $start->utc())
            ->where('transitioned_at', '<', $end->utc())
            ->where('quality_status', 'reservation_after_transition')
            ->count();

        $this->applyDeduplicatedGroups($buckets, $quality, $groups, 'cancellations');
    }

    private function opportunityEvent(
        SalesforceOpportunity $opportunity,
        mixed $date,
        array $rosterContext,
        array &$quality,
    ): array {
        return [
            'month' => $this->monthKey($date),
            'attribution' => $this->attribution(
                $opportunity->owner_id,
                $opportunity->owner_name,
                $date,
                $rosterContext,
                $quality,
            ),
        ];
    }

    private function applyDeduplicatedGroups(
        array &$buckets,
        array &$quality,
        array $groups,
        string $metric,
    ): void {
        foreach ($groups as $events) {
            $month = (string) data_get($events, '0.month');
            $attributions = collect($events)->pluck('attribution');
            $signatures = $attributions->map(fn (array $item): string => $this->attributionSignature($item))->unique();
            $attribution = $signatures->count() === 1
                ? $attributions->first()
                : $this->monthlyRoster->incidentAttribution();

            if ($signatures->count() !== 1 && $metric !== 'reservations_active') {
                $quality['duplicate_conflict_groups']++;
            }

            $this->increment($buckets, $month, $attribution, $metric);

            if ($metric !== 'sales') {
                continue;
            }

            $margins = collect($events)->pluck('margin')->filter(fn ($value): bool => $value !== null)->uniqueStrict()->values();
            if ($margins->count() === 1) {
                $this->increment($buckets, $month, $attribution, 'margin_total', (float) $margins->first());
                $this->increment($buckets, $month, $attribution, 'sales_with_margin');
            } else {
                $this->increment($buckets, $month, $attribution, 'sales_without_margin');
                if ($margins->count() > 1) {
                    $quality['margin_conflict_groups']++;
                }
            }
        }
    }

    private function attribution(
        mixed $userId,
        mixed $userName,
        mixed $eventAt,
        array $rosterContext,
        array &$quality,
    ): array {
        $userId = trim((string) $userId);
        if ($userId === '') {
            $quality['unresolved_attribution_events']++;

            return $this->monthlyRoster->incidentAttribution();
        }

        if (! $rosterContext['users']->has($userId)) {
            $quality['unresolved_attribution_events']++;

            return $this->monthlyRoster->incidentAttribution();
        }

        $attribution = $this->monthlyRoster->attribution($rosterContext, $userId, $userName, $eventAt);
        if (! $attribution['delegation_certified']) {
            $quality['uncertified_historical_events']++;
        }

        return $attribution;
    }

    private function increment(array &$buckets, string $month, array $attribution, string $metric, int|float $amount = 1): void
    {
        if (! array_key_exists($month, $buckets)) {
            return;
        }

        $key = $this->attributionSignature($attribution);
        $buckets[$month][$key] ??= $this->emptyBucket($attribution);
        $buckets[$month][$key][$metric] += $amount;
    }

    private function seedCertifiedRoster(array &$buckets, array $monthKeys, array $rosterContext): void
    {
        foreach ($monthKeys as $month) {
            foreach ($this->monthlyRoster->rosterForMonth($rosterContext, $month) as $attribution) {
                $buckets[$month][$this->attributionSignature($attribution)] ??= $this->emptyBucket($attribution);
            }
        }
    }

    private function emptyBucket(array $attribution): array
    {
        return $attribution + [
            'leads' => 0,
            'opportunities' => 0,
            'reservations_total' => 0,
            'reservations_active' => 0,
            'sales' => 0,
            'cancellations' => 0,
            'margin_total' => 0.0,
            'sales_with_margin' => 0,
            'sales_without_margin' => 0,
        ];
    }

    private function finalizeRow(array $bucket, int $target, bool $cancellationsAvailable): array
    {
        $isCommercial = filled($bucket['commercial_id']);
        $objective = $isCommercial ? $target : null;
        $fulfillment = $isCommercial ? $this->percentage($bucket['reservations_total'], $target) : null;

        return array_merge($bucket, [
            'cancellations' => $cancellationsAvailable ? $bucket['cancellations'] : null,
            'objective' => $objective,
            'fulfillment_pct' => $fulfillment,
            'traffic_light' => $fulfillment === null ? null : $this->trafficLight((float) $fulfillment),
            'lead_to_reservation_pct' => $this->percentage($bucket['reservations_total'], $bucket['leads']),
            'opportunity_to_reservation_pct' => $this->percentage($bucket['reservations_total'], $bucket['opportunities']),
            'reservation_to_sale_pct' => $this->percentage($bucket['sales'], $bucket['reservations_total']),
            'cancellation_pct' => $cancellationsAvailable
                ? $this->percentage($bucket['cancellations'], $bucket['reservations_total'])
                : null,
            'average_margin_per_sale' => $bucket['sales_with_margin'] > 0
                ? round($bucket['margin_total'] / $bucket['sales_with_margin'], 2)
                : null,
            'margin_coverage_pct' => $this->percentage($bucket['sales_with_margin'], $bucket['sales']),
            'ranking' => null,
        ]);
    }

    private function applyTeamComparisonsAndRanking(Collection $rows): Collection
    {
        $teamStats = $rows
            ->filter(fn (array $row): bool => $row['delegation_certified'] && $row['commercial_id'] !== null)
            ->groupBy('delegation')
            ->map(function (Collection $team): array {
                $salesWithMargin = (int) $team->sum('sales_with_margin');
                $marginTotal = (float) $team->sum('margin_total');

                return [
                    'average_reservations' => round((float) $team->average('reservations_total'), 2),
                    'lead_to_reservation_pct' => $this->percentage($team->sum('reservations_total'), $team->sum('leads')),
                    'opportunity_to_reservation_pct' => $this->percentage($team->sum('reservations_total'), $team->sum('opportunities')),
                    'reservation_to_sale_pct' => $this->percentage($team->sum('sales'), $team->sum('reservations_total')),
                    'cancellation_pct' => $team->contains(fn (array $row): bool => $row['cancellations'] === null)
                        ? null
                        : $this->percentage($team->sum('cancellations'), $team->sum('reservations_total')),
                    'margin_total' => round($marginTotal, 2),
                    'average_margin_per_sale' => $salesWithMargin > 0 ? round($marginTotal / $salesWithMargin, 2) : null,
                    'sales_with_margin' => $salesWithMargin,
                    'sales_without_margin' => (int) $team->sum('sales_without_margin'),
                ];
            });

        $eligible = $rows
            ->filter(fn (array $row): bool => $row['ranking_eligible'])
            ->sortBy([['fulfillment_pct', 'desc'], ['commercial', 'asc']]);
        $ranks = [];
        $position = 0;
        $previous = null;
        foreach ($eligible as $row) {
            $value = $row['fulfillment_pct'];
            if ($previous === null || $value !== $previous) {
                $position++;
                $previous = $value;
            }
            $ranks[$this->attributionSignature($row)] = $position;
        }

        return $rows->map(function (array $row) use ($teamStats, $ranks): array {
            $team = $row['delegation_certified'] ? $teamStats->get($row['delegation']) : null;
            $average = data_get($team, 'average_reservations');

            return array_merge($row, [
                'delegation_average_reservations' => $average,
                'delegation_reservations_deviation' => $average === null
                    ? null
                    : round($row['reservations_total'] - $average, 2),
                'delegation_lead_to_reservation_pct' => data_get($team, 'lead_to_reservation_pct'),
                'delegation_opportunity_to_reservation_pct' => data_get($team, 'opportunity_to_reservation_pct'),
                'delegation_reservation_to_sale_pct' => data_get($team, 'reservation_to_sale_pct'),
                'delegation_cancellation_pct' => data_get($team, 'cancellation_pct'),
                'delegation_margin_total' => data_get($team, 'margin_total'),
                'delegation_average_margin_per_sale' => data_get($team, 'average_margin_per_sale'),
                'ranking' => $ranks[$this->attributionSignature($row)] ?? null,
            ]);
        })->sortBy([
            [fn (array $row): int => $row['ranking'] ?? PHP_INT_MAX, 'asc'],
            ['commercial', 'asc'],
        ])->values();
    }

    private function evolutionRow(string $month, Collection $rows, bool $cancellationsAvailable): array
    {
        $reservations = (int) $rows->sum('reservations_total');
        $objective = (int) $rows->sum(fn (array $row): int => is_numeric($row['objective']) ? (int) $row['objective'] : 0);
        $leads = (int) $rows->sum('leads');
        $opportunities = (int) $rows->sum('opportunities');
        $sales = (int) $rows->sum('sales');
        $cancellations = $cancellationsAvailable ? (int) $rows->sum('cancellations') : null;
        $marginTotal = round((float) $rows->sum('margin_total'), 2);
        $salesWithMargin = (int) $rows->sum('sales_with_margin');

        return [
            'month' => $month,
            'leads' => $leads,
            'opportunities' => $opportunities,
            'reservations_total' => $reservations,
            'reservations_active' => (int) $rows->sum('reservations_active'),
            'sales' => $sales,
            'cancellations' => $cancellations,
            'objective' => $objective,
            'fulfillment_pct' => $this->percentage($reservations, $objective),
            'lead_to_reservation_pct' => $this->percentage($reservations, $leads),
            'opportunity_to_reservation_pct' => $this->percentage($reservations, $opportunities),
            'reservation_to_sale_pct' => $this->percentage($sales, $reservations),
            'cancellation_pct' => $cancellationsAvailable
                ? $this->percentage($cancellations, $reservations)
                : null,
            'margin_total' => $marginTotal,
            'average_margin_per_sale' => $salesWithMargin > 0 ? round($marginTotal / $salesWithMargin, 2) : null,
            'sales_with_margin' => $salesWithMargin,
            'sales_without_margin' => (int) $rows->sum('sales_without_margin'),
        ];
    }

    private function applyOrganisationFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when(filled($filters['zone']), fn (Collection $items) => $items->where('zone', $filters['zone']))
            ->when(filled($filters['delegation']), fn (Collection $items) => $items->where('delegation', $filters['delegation']))
            ->values();
    }

    private function filterOptions(Collection $rows, array $filters): array
    {
        $evaluable = $rows->where('delegation_certified', true);
        $delegationRows = filled($filters['zone']) ? $evaluable->where('zone', $filters['zone']) : $evaluable;
        $commercialRows = $rows->filter(fn (array $row): bool => filled($row['commercial_id']));
        if (filled($filters['zone'])) {
            $commercialRows = $commercialRows
                ->where('delegation_certified', true)
                ->where('zone', $filters['zone']);
        }
        if (filled($filters['delegation'])) {
            $commercialRows = $commercialRows
                ->where('delegation_certified', true)
                ->where('delegation', $filters['delegation']);
        }

        return [
            'zones' => $evaluable->pluck('zone')->filter()->unique()->sort()->values()->all(),
            'delegations' => $delegationRows->pluck('delegation')->filter()->unique()->sort()->values()->all(),
            'commercials' => $commercialRows
                ->filter(fn (array $row): bool => filled($row['commercial_id']))
                ->map(fn (array $row): array => ['id' => $row['commercial_id'], 'name' => $row['commercial']])
                ->unique('id')->sortBy('name')->values()->all(),
        ];
    }

    public function historyCoverage(Collection $months): array
    {
        $globalStart = $months->first()->startOfMonth()->utc();
        $globalEnd = $months->last()->addMonth()->startOfMonth()->utc();
        $now = CarbonImmutable::now('UTC');
        $currentMonthStart = $now->setTimezone(self::DATASET_TIMEZONE)->startOfMonth()->utc();
        $intervals = SalesforceOpportunityHistorySyncInterval::query()
            ->where('range_start', '<', $globalEnd)
            ->where('range_end', '>', $globalStart)
            ->orderBy('range_start')
            ->get(['range_start', 'range_end', 'unresolved_dependencies', 'is_kpi_certified']);
        $blockingTransitions = SalesforceOpportunityStageTransition::query()
            ->whereIn('quality_status', ['opportunity_not_local', 'previous_stage_not_demonstrated'])
            ->where('transitioned_at', '>=', $globalStart->toDateTimeString())
            ->where('transitioned_at', '<', $globalEnd->toDateTimeString())
            ->get(['transitioned_at', 'quality_status']);

        return $months->mapWithKeys(function (CarbonImmutable $month) use ($intervals, $blockingTransitions, $currentMonthStart): array {
            $start = $month->startOfMonth()->utc();
            $calendarEnd = $month->addMonth()->startOfMonth()->utc();
            $monthIntervals = $intervals->filter(function (SalesforceOpportunityHistorySyncInterval $interval) use ($start, $calendarEnd): bool {
                return $this->intervalBoundary($interval, 'range_start')->lessThan($calendarEnd)
                    && $this->intervalBoundary($interval, 'range_end')->greaterThan($start);
            });
            $sourceCutoff = $monthIntervals
                ->map(fn (SalesforceOpportunityHistorySyncInterval $interval): CarbonImmutable => $this->intervalBoundary($interval, 'range_end')->min($calendarEnd))
                ->sortDesc()
                ->first();
            $isCurrentMonth = $start->equalTo($currentMonthStart);
            $end = $isCurrentMonth ? ($sourceCutoff ?? $start) : $calendarEnd;
            $cursor = $start;
            $hasOverlap = $monthIntervals->isNotEmpty();
            $pendingTransitions = $blockingTransitions->filter(function (SalesforceOpportunityStageTransition $transition) use ($start, $end): bool {
                $transitionedAt = $this->transitionBoundary($transition);

                return $transitionedAt->greaterThanOrEqualTo($start) && $transitionedAt->lessThan($end);
            });
            $unresolvedDependencies = $pendingTransitions->count();

            if ($end->lessThanOrEqualTo($start)) {
                return [$month->format('Y-m') => [
                    'status' => 'uncovered',
                    'range_start' => $start->toIso8601String(),
                    'range_end' => $end->toIso8601String(),
                    'source_cutoff_at' => $sourceCutoff?->toIso8601String(),
                    'certified_until' => null,
                    'unresolved_dependencies' => $unresolvedDependencies,
                ]];
            }

            foreach ($monthIntervals->where('is_kpi_certified', true) as $interval) {
                $intervalStart = $this->intervalBoundary($interval, 'range_start')->max($start);
                $intervalEnd = $this->intervalBoundary($interval, 'range_end')->min($end);
                if ($intervalEnd->lessThanOrEqualTo($start) || $intervalStart->greaterThanOrEqualTo($end)) {
                    continue;
                }

                if ($intervalStart->greaterThan($cursor)) {
                    break;
                }

                if ($intervalEnd->greaterThan($cursor)) {
                    $cursor = $intervalEnd;
                }

                if ($cursor->greaterThanOrEqualTo($end)) {
                    break;
                }
            }

            $status = $cursor->greaterThanOrEqualTo($end) && $unresolvedDependencies === 0
                ? 'covered'
                : ($hasOverlap ? 'partial' : 'uncovered');
            $certifiedUntil = $cursor->greaterThan($start) ? $cursor : null;
            if ($pendingTransitions->isNotEmpty()) {
                $firstPendingAt = $pendingTransitions
                    ->map(fn (SalesforceOpportunityStageTransition $transition): CarbonImmutable => $this->transitionBoundary($transition))
                    ->sort()
                    ->first();
                $certifiedUntil = $firstPendingAt->greaterThan($start)
                    ? ($certifiedUntil?->min($firstPendingAt) ?? $firstPendingAt)
                    : null;
            }

            return [$month->format('Y-m') => [
                'status' => $status,
                'range_start' => $start->toIso8601String(),
                'range_end' => $end->toIso8601String(),
                'source_cutoff_at' => $sourceCutoff?->toIso8601String(),
                'certified_until' => $certifiedUntil?->toIso8601String(),
                'unresolved_dependencies' => $unresolvedDependencies,
            ]];
        })->all();
    }

    private function intervalBoundary(SalesforceOpportunityHistorySyncInterval $interval, string $attribute): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $interval->getRawOriginal($attribute), 'UTC');
    }

    private function transitionBoundary(SalesforceOpportunityStageTransition $transition): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $transition->getRawOriginal('transitioned_at'), 'UTC');
    }

    private function targets(Collection $months): array
    {
        $now = now();
        CommercialPerformanceMonthlyTarget::query()->insertOrIgnore(
            $months->map(fn (CarbonImmutable $month): array => [
                'month' => $month->toDateString(),
                'reservations_target' => CommercialPerformanceMonthlyTarget::DEFAULT_RESERVATIONS_TARGET,
                'is_explicit' => false,
                'updated_by_report_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        );

        $stored = CommercialPerformanceMonthlyTarget::query()
            ->whereIn('month', $months->map->toDateString())
            ->get()
            ->keyBy(fn (CommercialPerformanceMonthlyTarget $target): string => $target->month->format('Y-m'));

        return $months->mapWithKeys(function (CarbonImmutable $month) use ($stored): array {
            $target = $stored->get($month->format('Y-m'));

            return [$month->format('Y-m') => [
                'value' => (int) $target->reservations_target,
                'is_explicit' => (bool) $target->is_explicit,
            ]];
        })->all();
    }

    private function normalizeFilters(array $filters): array
    {
        return [
            'month' => (string) $filters['month'],
            'zone' => trim((string) ($filters['zone'] ?? '')),
            'delegation' => trim((string) ($filters['delegation'] ?? '')),
            'commercial' => trim((string) ($filters['commercial'] ?? '')),
        ];
    }

    private function monthKey(mixed $date): string
    {
        return CarbonImmutable::parse($date)->setTimezone(self::DATASET_TIMEZONE)->format('Y-m');
    }

    private function inRange(mixed $date, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if (blank($date)) {
            return false;
        }

        $value = CarbonImmutable::parse($date)->setTimezone(self::DATASET_TIMEZONE);

        return $value->greaterThanOrEqualTo($start) && $value->lessThan($end);
    }

    private function eventKey(?SalesforceOpportunity $opportunity, mixed $date): string
    {
        return ($this->vehicleIdentity($opportunity) ?: 'opportunity:'.$opportunity?->salesforce_id)
            .'|'.CarbonImmutable::parse($date)->toDateString();
    }

    private function vehicleIdentity(?SalesforceOpportunity $opportunity): ?string
    {
        if ($opportunity === null) {
            return null;
        }

        $vehicleId = trim((string) $opportunity->vehicle_interest_id);
        if ($vehicleId !== '') {
            return 'vehicle:'.mb_strtolower($vehicleId);
        }

        $plate = preg_replace('/[^\pL\pN]+/u', '', mb_strtoupper(trim((string) $opportunity->vehicle_plate)));

        return $plate !== '' ? 'plate:'.$plate : null;
    }

    private function attributionSignature(array $attribution): string
    {
        return (string) ($attribution['commercial_id'] ?? 'incident');
    }

    private function percentage(int|float $numerator, int|float $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }

    public function trafficLight(float $fulfillment): string
    {
        return match (true) {
            $fulfillment >= 100 => 'green',
            $fulfillment >= 80 => 'yellow',
            $fulfillment >= 60 => 'orange',
            default => 'red',
        };
    }

    private function dataVersion(): array
    {
        return [
            'leads' => [SalesforceLead::query()->count(), SalesforceLead::query()->max('updated_at')],
            'opportunities' => [SalesforceOpportunity::query()->count(), SalesforceOpportunity::query()->max('updated_at')],
            'transitions' => [SalesforceOpportunityStageTransition::query()->count(), SalesforceOpportunityStageTransition::query()->max('updated_at')],
            'history_coverage' => [SalesforceOpportunityHistorySyncInterval::query()->count(), SalesforceOpportunityHistorySyncInterval::query()->max('updated_at')],
            'delegations' => [CommercialDelegationSnapshot::query()->count(), CommercialDelegationSnapshot::query()->max('updated_at')],
            'targets' => [
                CommercialPerformanceMonthlyTarget::query()->count(),
                CommercialPerformanceMonthlyTarget::query()->max('updated_at'),
                CommercialPerformanceMonthlyTarget::query()->sum('reservations_target'),
                CommercialPerformanceMonthlyTarget::query()->sum('is_explicit'),
            ],
        ];
    }
}
