<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityStageTransition;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Reports\MonthlyCommercial\MonthlyCommercialLeadEnricher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CommercialPerformanceAuditService
{
    private const DATASET_TIMEZONE = 'Europe/Madrid';

    public function __construct(
        private readonly CommercialPerformanceMonthlyRosterService $monthlyRoster,
        private readonly CommercialPerformanceDatasetService $dataset,
        private readonly MonthlyCommercialLeadEnricher $leadEnricher,
        private readonly LeadRecordTypeNormalizer $recordTypeNormalizer,
    ) {}

    public function payload(array $filters): array
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month'], self::DATASET_TIMEZONE)->startOfMonth();
        $end = $month->addMonth();
        $months = collect([$month]);
        $context = $this->monthlyRoster->context($months);
        $coverage = $this->dataset->historyCoverage($months)[$filters['month']];
        $rows = collect();

        $this->appendLeads($rows, $month, $end, $context);
        $this->appendOpportunities($rows, $month, $end, $context);
        $this->appendTransitions($rows, $month, $end, $context, $coverage['status']);
        $this->applyDeduplication($rows);

        if (filled($filters['commercial'] ?? null)) {
            $rows = $rows->where('commercial_id', $filters['commercial']);
        }

        $rows = $rows->sortBy([['event_at', 'desc'], ['event_type', 'asc'], ['source_id', 'asc']])->values();
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 100), 1), 200);

        return [
            'ok' => true,
            'month' => $filters['month'],
            'coverage_status' => $coverage['status'],
            'coverage_source_cutoff_at' => $coverage['source_cutoff_at'],
            'coverage_certified_until' => $coverage['certified_until'],
            'coverage_unresolved_dependencies' => $coverage['unresolved_dependencies'],
            'items' => $rows->forPage($page, $perPage)->values()->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => max((int) ceil($rows->count() / $perPage), 1),
            ],
            'pii_excluded' => true,
        ];
    }

    private function appendLeads(Collection $rows, CarbonImmutable $start, CarbonImmutable $end, array $context): void
    {
        SalesforceLead::query()
            ->where('fecha_asignacion', '>=', $start->utc())
            ->where('fecha_asignacion', '<', $end->utc())
            ->select([
                'id', 'salesforce_id', 'status', 'record_type_name', 'record_type_normalized', 'is_deleted',
                'owner_id', 'owner_name', 'persona_que_trabajo_id', 'persona_que_trabajo_name',
                'propietario_descarte_id', 'propietario_descarte_name', 'fecha_asignacion',
            ])
            ->orderBy('id')
            ->chunkById(1000, function ($leads) use ($rows, $context): void {
                foreach ($leads as $lead) {
                    $responsible = $this->leadEnricher->effectiveResponsible($lead);
                    $attribution = $this->monthlyRoster->attribution(
                        $context,
                        $responsible['id'] ?? null,
                        $responsible['name'] ?? null,
                        $lead->fecha_asignacion,
                    );
                    $type = $lead->record_type_normalized ?: $this->recordTypeNormalizer->normalize($lead->record_type_name);
                    $eligibleType = in_array($type, $this->recordTypeNormalizer->ventaFilterTypes(), true);
                    $counted = ! $lead->is_deleted && $eligibleType && $context['users']->has($attribution['commercial_id']);
                    $rows->push($this->row(
                        eventType: 'lead',
                        sourceId: (string) $lead->salesforce_id,
                        eventAt: $lead->fecha_asignacion,
                        attribution: $attribution,
                        leadId: (string) $lead->salesforce_id,
                        counted: $counted,
                        exclusion: $counted ? null : ($lead->is_deleted ? 'deleted' : ($eligibleType ? 'non_commercial_responsible' : 'record_type_excluded')),
                    ));
                }
            });
    }

    private function appendOpportunities(Collection $rows, CarbonImmutable $start, CarbonImmutable $end, array $context): void
    {
        SalesforceOpportunity::query()
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
                'id', 'salesforce_id', 'record_type_name', 'stage_name', 'owner_id', 'owner_name',
                'created_date', 'reservation', 'reservation_date', 'cv_signed', 'cv_signed_date',
                'vehicle_interest_id', 'vehicle_plate',
            ])
            ->orderBy('id')
            ->chunkById(1000, function ($opportunities) use ($rows, $start, $end, $context): void {
                foreach ($opportunities as $opportunity) {
                    $eligibleType = in_array($opportunity->record_type_name, ['Venta', 'Cambio'], true);
                    if ($this->inRange($opportunity->created_date, $start, $end)) {
                        $this->pushOpportunityEvent($rows, $opportunity, 'opportunity', $opportunity->created_date, $context, $eligibleType);
                    }
                    if ($this->inRange($opportunity->reservation_date, $start, $end)) {
                        $this->pushOpportunityEvent($rows, $opportunity, 'reservation', $opportunity->reservation_date, $context, $eligibleType && $opportunity->reservation);
                    }
                    if ($this->inRange($opportunity->cv_signed_date, $start, $end)) {
                        $validSale = $eligibleType && $opportunity->cv_signed
                            && strcasecmp((string) $opportunity->stage_name, 'Cerrada Perdida') !== 0;
                        $this->pushOpportunityEvent($rows, $opportunity, 'sale', $opportunity->cv_signed_date, $context, $validSale);
                    }
                }
            });
    }

    private function appendTransitions(
        Collection $rows,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $context,
        string $coverageStatus,
    ): void {
        $transitions = SalesforceOpportunityStageTransition::query()
            ->where('transitioned_at', '>=', $start->utc())
            ->where('transitioned_at', '<', $end->utc())
            ->orderBy('id')
            ->get();
        $opportunities = SalesforceOpportunity::query()
            ->whereIn('salesforce_id', $transitions->pluck('opportunity_salesforce_id')->unique())
            ->get(['salesforce_id', 'vehicle_interest_id', 'vehicle_plate'])
            ->keyBy('salesforce_id');

        foreach ($transitions as $transition) {
            $attribution = $this->monthlyRoster->attribution(
                $context,
                $transition->owner_id,
                $transition->owner_name,
                $transition->transitioned_at,
            );
            $commerciallyEligible = filled($transition->owner_id) && $context['users']->has($transition->owner_id);
            $counted = $coverageStatus === 'covered'
                && $transition->is_reservation_cancellation
                && $commerciallyEligible;
            $opportunity = $opportunities->get($transition->opportunity_salesforce_id);
            $rows->push($this->row(
                eventType: 'cancellation_transition',
                sourceId: (string) $transition->salesforce_history_id,
                eventAt: $transition->transitioned_at,
                attribution: $attribution,
                opportunityId: (string) $transition->opportunity_salesforce_id,
                counted: $counted,
                exclusion: match (true) {
                    $counted => null,
                    $coverageStatus !== 'covered' => 'history_coverage_'.$coverageStatus,
                    ! $transition->is_reservation_cancellation => $transition->quality_status,
                    ! $commerciallyEligible => 'non_commercial_responsible',
                    default => 'business_rule_excluded',
                },
                coverageStatus: $coverageStatus,
                deduplicationKey: $this->opportunityIdentity($opportunity, (string) $transition->opportunity_salesforce_id).'|'.$transition->transitioned_at->toDateString(),
            ));
        }
    }

    private function pushOpportunityEvent(
        Collection $rows,
        SalesforceOpportunity $opportunity,
        string $eventType,
        mixed $eventAt,
        array $context,
        bool $counted,
    ): void {
        $attribution = $this->monthlyRoster->attribution($context, $opportunity->owner_id, $opportunity->owner_name, $eventAt);
        $commerciallyEligible = filled($opportunity->owner_id) && $context['users']->has($opportunity->owner_id);
        $countedInMetric = $counted && $commerciallyEligible;
        $rows->push($this->row(
            eventType: $eventType,
            sourceId: (string) $opportunity->salesforce_id,
            eventAt: $eventAt,
            attribution: $attribution,
            opportunityId: (string) $opportunity->salesforce_id,
            counted: $countedInMetric,
            exclusion: match (true) {
                $countedInMetric => null,
                $counted && ! $commerciallyEligible => 'non_commercial_responsible',
                default => 'business_rule_excluded',
            },
            deduplicationKey: $this->opportunityIdentity($opportunity).'|'.CarbonImmutable::parse($eventAt)->toDateString(),
        ));
    }

    private function row(
        string $eventType,
        string $sourceId,
        mixed $eventAt,
        array $attribution,
        ?string $leadId = null,
        ?string $opportunityId = null,
        bool $counted = true,
        ?string $exclusion = null,
        ?string $coverageStatus = null,
        ?string $deduplicationKey = null,
    ): array {
        return [
            'event_type' => $eventType,
            'source_id' => $sourceId,
            'lead_id' => $leadId,
            'opportunity_id' => $opportunityId,
            'event_at' => CarbonImmutable::parse($eventAt)->setTimezone(self::DATASET_TIMEZONE)->toIso8601String(),
            'commercial_id' => $attribution['commercial_id'],
            'commercial' => $attribution['commercial'],
            'delegation' => $attribution['delegation'],
            'zone' => $attribution['zone'],
            'delegation_certified' => $attribution['delegation_certified'],
            'delegation_status' => $attribution['delegation_status'],
            'delegation_issue' => $attribution['delegation_issue'] ?? null,
            'coverage_status' => $coverageStatus,
            'counted_in_metric' => $counted,
            'exclusion_reason' => $exclusion,
            'deduplication_key' => $deduplicationKey,
            'deduplication_status' => $deduplicationKey === null ? null : 'unique',
            'metric_attribution' => $attribution['commercial_id'],
        ];
    }

    private function applyDeduplication(Collection $rows): void
    {
        $groups = $rows
            ->filter(fn (array $row): bool => $row['counted_in_metric']
                && in_array($row['event_type'], ['reservation', 'sale', 'cancellation_transition'], true)
                && filled($row['deduplication_key']))
            ->groupBy(fn (array $row): string => $row['event_type'].'|'.$row['deduplication_key'], true);

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $representativeKey = $group->keys()->first();
            $conflict = $group->pluck('commercial_id')->uniqueStrict()->count() > 1;
            foreach ($group as $key => $row) {
                if ($key === $representativeKey) {
                    $row['deduplication_status'] = $conflict ? 'attribution_conflict_representative' : 'counted_representative';
                    $row['metric_attribution'] = $conflict ? 'data_quality_incident' : $row['commercial_id'];
                } else {
                    $row['counted_in_metric'] = false;
                    $row['exclusion_reason'] = 'deduplicated_event';
                    $row['deduplication_status'] = 'excluded_duplicate';
                }
                $rows->put($key, $row);
            }
        }
    }

    private function opportunityIdentity(?SalesforceOpportunity $opportunity, ?string $fallbackId = null): string
    {
        if (filled($opportunity?->vehicle_interest_id)) {
            return 'vehicle:'.mb_strtolower((string) $opportunity->vehicle_interest_id);
        }

        $plate = preg_replace('/[^\pL\pN]+/u', '', mb_strtoupper(trim((string) $opportunity?->vehicle_plate)));

        return $plate !== '' ? 'plate:'.$plate : 'opportunity:'.($opportunity?->salesforce_id ?: $fallbackId);
    }

    private function inRange(mixed $date, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if (blank($date)) {
            return false;
        }

        $value = CarbonImmutable::parse($date)->setTimezone(self::DATASET_TIMEZONE);

        return $value->greaterThanOrEqualTo($start) && $value->lessThan($end);
    }
}
