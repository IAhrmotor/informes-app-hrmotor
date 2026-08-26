<?php

namespace App\Services\Reports\ReservationsSales\Sync;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityHistorySyncInterval;
use App\Models\SalesforceOpportunityStageTransition;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesforceOpportunityHistorySyncService
{
    private const CHUNK_DAYS = 14;

    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $observationCutoff = CarbonImmutable::now('UTC')->startOfSecond();
        $start = CarbonImmutable::parse($periodStart)->utc();
        $requestedEnd = CarbonImmutable::parse($periodEnd)->utc();
        $end = $requestedEnd->min($observationCutoff);
        $candidateRows = collect();
        $coveredIntervals = collect();
        $queried = 0;

        while ($start->lessThan($end)) {
            $chunkEnd = $start->addDays(self::CHUNK_DAYS)->min($end);
            $rows = $this->client->query($this->candidateSoql($start, $chunkEnd));
            $queried += count($rows);
            $candidateRows->push(...$rows);
            $coveredIntervals->push([
                'range_start' => $start,
                'range_end' => $chunkEnd,
                'queried_rows' => count($rows),
            ]);
            $start = $chunkEnd;
        }

        $candidateRows = $candidateRows
            ->filter(fn (array $row): bool => $this->isClosedLost(data_get($row, 'StageName')))
            ->filter(fn (array $row): bool => filled(data_get($row, 'Id')) && filled(data_get($row, 'OpportunityId')))
            ->unique('Id')
            ->values();

        $opportunityIds = $candidateRows->pluck('OpportunityId')->unique()->values();
        $opportunities = SalesforceOpportunity::query()
            ->whereIn('salesforce_id', $opportunityIds)
            ->get(['salesforce_id', 'reservation', 'reservation_date', 'owner_id', 'owner_name'])
            ->keyBy('salesforce_id');
        $candidateIds = $candidateRows->pluck('Id')->flip();
        $transitions = collect();
        $processedCandidateIds = collect();
        $nonTransitions = 0;

        foreach ($opportunityIds->chunk(100) as $idChunk) {
            $history = collect($this->client->query($this->sequenceSoql($idChunk)))
                ->sortBy(fn (array $row): string => (string) data_get($row, 'CreatedDate').'|'.(string) data_get($row, 'Id'))
                ->groupBy('OpportunityId');

            foreach ($history as $opportunityId => $sequence) {
                $previous = null;

                foreach ($sequence as $row) {
                    $historyId = (string) data_get($row, 'Id');
                    $stage = trim((string) data_get($row, 'StageName'));

                    if ($candidateIds->has($historyId) && $this->isClosedLost($stage)) {
                        $processedCandidateIds->push($historyId);
                        $opportunity = $opportunities->get($opportunityId);
                        $transitionedAt = CarbonImmutable::parse(data_get($row, 'CreatedDate'))->utc();

                        if ($previous === null) {
                            $transitions->push($this->transitionRow(
                                $row,
                                $opportunity,
                                null,
                                false,
                                'previous_stage_not_demonstrated',
                            ));
                        } elseif ($this->isClosedLost(data_get($previous, 'StageName'))) {
                            $nonTransitions++;
                        } else {
                            [$isCancellation, $qualityStatus] = $this->cancellationQuality($opportunity, $transitionedAt);
                            $transitions->push($this->transitionRow(
                                $row,
                                $opportunity,
                                data_get($previous, 'StageName'),
                                $isCancellation,
                                $qualityStatus,
                            ));
                        }
                    }

                    $previous = $row;
                }
            }
        }

        foreach ($candidateRows->whereNotIn('Id', $processedCandidateIds->unique()) as $row) {
            $opportunity = $opportunities->get((string) data_get($row, 'OpportunityId'));
            $transitions->push($this->transitionRow(
                $row,
                $opportunity,
                null,
                false,
                'previous_stage_not_demonstrated',
            ));
        }

        DB::transaction(function () use ($transitions, $coveredIntervals): void {
            foreach ($transitions->chunk(500) as $chunk) {
                SalesforceOpportunityStageTransition::query()->upsert(
                    $chunk->map(fn (array $row): array => $this->serialize($row))->all(),
                    ['salesforce_history_id'],
                    ['previous_stage', 'new_stage', 'transitioned_at', 'reservation_date', 'owner_id', 'owner_name', 'source', 'is_reservation_cancellation', 'quality_status', 'synced_at', 'updated_at'],
                );
            }

            $now = now();
            SalesforceOpportunityHistorySyncInterval::query()->upsert(
                $coveredIntervals->map(function (array $interval) use ($transitions, $now): array {
                    $rangeStart = CarbonImmutable::parse($interval['range_start'])->utc();
                    $rangeEnd = CarbonImmutable::parse($interval['range_end'])->utc();
                    $unresolvedDependencies = $transitions
                        ->whereIn('quality_status', $this->blockingQualityStatuses())
                        ->filter(fn (array $transition): bool => CarbonImmutable::parse($transition['transitioned_at'])->utc()->greaterThanOrEqualTo($rangeStart)
                            && CarbonImmutable::parse($transition['transitioned_at'])->utc()->lessThan($rangeEnd))
                        ->count();

                    return [
                        'range_start' => $rangeStart->toDateTimeString(),
                        'range_end' => $rangeEnd->toDateTimeString(),
                        'completed_at' => $now,
                        'source' => 'OpportunityHistory',
                        'queried_rows' => $interval['queried_rows'],
                        'unresolved_dependencies' => $unresolvedDependencies,
                        'is_kpi_certified' => $unresolvedDependencies === 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all(),
                ['range_start', 'range_end', 'source'],
                ['completed_at', 'queried_rows', 'unresolved_dependencies', 'is_kpi_certified', 'updated_at'],
            );
        });

        return [
            'queried' => $queried,
            'candidates' => $candidateRows->count(),
            'saved' => $transitions->count(),
            'verified_cancellations' => $transitions->where('is_reservation_cancellation', true)->count(),
            'unverifiable' => $transitions->where('quality_status', 'previous_stage_not_demonstrated')->count(),
            'non_transitions' => $nonTransitions,
            'covered_intervals' => $coveredIntervals->count(),
            'unresolved_dependencies' => $transitions->whereIn('quality_status', $this->blockingQualityStatuses())->count(),
            'observation_cutoff_at' => $observationCutoff->toIso8601String(),
            'effective_end_at' => $end->toIso8601String(),
            'source' => 'OpportunityHistory',
        ];
    }

    private function transitionRow(
        array $row,
        ?SalesforceOpportunity $opportunity,
        mixed $previousStage,
        bool $isCancellation,
        string $qualityStatus,
    ): array {
        return [
            'salesforce_history_id' => (string) data_get($row, 'Id'),
            'opportunity_salesforce_id' => (string) data_get($row, 'OpportunityId'),
            'previous_stage' => $previousStage,
            'new_stage' => trim((string) data_get($row, 'StageName')),
            'transitioned_at' => CarbonImmutable::parse(data_get($row, 'CreatedDate'))->utc(),
            'reservation_date' => $opportunity?->reservation_date,
            'owner_id' => $opportunity?->owner_id,
            'owner_name' => $opportunity?->owner_name,
            'source' => 'OpportunityHistory',
            'is_reservation_cancellation' => $isCancellation,
            'quality_status' => $qualityStatus,
            'synced_at' => now(),
        ];
    }

    private function blockingQualityStatuses(): array
    {
        return ['opportunity_not_local', 'previous_stage_not_demonstrated'];
    }

    private function candidateSoql(CarbonInterface $start, CarbonInterface $end): string
    {
        return 'SELECT Id, OpportunityId, StageName, CreatedDate FROM OpportunityHistory '
            .'WHERE CreatedDate >= '.$this->soqlDateTime($start)
            .' AND CreatedDate < '.$this->soqlDateTime($end)
            .' ORDER BY CreatedDate ASC';
    }

    private function sequenceSoql(Collection $opportunityIds): string
    {
        $ids = $opportunityIds
            ->map(fn (string $id): string => "'".str_replace("'", "\\'", $id)."'")
            ->implode(', ');

        return "SELECT Id, OpportunityId, StageName, CreatedDate FROM OpportunityHistory WHERE OpportunityId IN ({$ids}) ORDER BY CreatedDate ASC";
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function isClosedLost(mixed $stage): bool
    {
        return strcasecmp(trim((string) $stage), 'Cerrada Perdida') === 0;
    }

    private function cancellationQuality(?SalesforceOpportunity $opportunity, CarbonImmutable $transitionedAt): array
    {
        if ($opportunity === null) {
            return [false, 'opportunity_not_local'];
        }

        if (! $opportunity->reservation || $opportunity->reservation_date === null) {
            return [false, 'reservation_not_demonstrated'];
        }

        if ($opportunity->reservation_date->startOfDay()->greaterThan($transitionedAt)) {
            return [false, 'reservation_after_transition'];
        }

        return [true, 'valid'];
    }

    private function serialize(array $row): array
    {
        return array_merge($row, [
            'transitioned_at' => CarbonImmutable::parse($row['transitioned_at'])->toDateTimeString(),
            'reservation_date' => filled($row['reservation_date'])
                ? CarbonImmutable::parse($row['reservation_date'])->toDateString()
                : null,
            'synced_at' => CarbonImmutable::parse($row['synced_at'])->toDateTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
