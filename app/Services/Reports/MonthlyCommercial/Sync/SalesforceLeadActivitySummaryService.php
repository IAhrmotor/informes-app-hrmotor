<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceActivity;
use App\Models\SalesforceLead;
use App\Models\SalesforceLeadActivitySummary;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SalesforceLeadActivitySummaryService
{
    public function __construct(
        private readonly ChangedRowUpsert $changedRowUpsert = new ChangedRowUpsert,
    ) {}

    public function recalculateForPeriod(CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        return $this->recalculateForPeriodWithStats($periodStart, $periodEnd)['saved'];
    }

    /** @return array{saved:int, inserted:int, updated:int, unchanged:int, summaries_changed:int, summaries_unchanged:int} */
    public function recalculateForPeriodWithStats(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $leadIds = SalesforceLead::query()
            ->where('created_date', '>=', $periodStart)
            ->where('created_date', '<', $periodEnd)
            ->pluck('salesforce_id');

        return $this->recalculateWithStats($leadIds);
    }

    public function recalculate(iterable $leadSalesforceIds): int
    {
        return $this->recalculateWithStats($leadSalesforceIds)['saved'];
    }

    /** @return array{saved:int, inserted:int, updated:int, unchanged:int, summaries_changed:int, summaries_unchanged:int} */
    public function recalculateWithStats(iterable $leadSalesforceIds): array
    {
        $stats = ['saved' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0];

        collect($leadSalesforceIds)
            ->filter()
            ->unique()
            ->values()
            ->chunk(500)
            ->each(function (Collection $chunk) use (&$stats): void {
                $chunkStats = $this->recalculateChunk($chunk);
                foreach ($stats as $key => $value) {
                    $stats[$key] += $chunkStats[$key];
                }
            });

        return [
            ...$stats,
            'summaries_changed' => $stats['inserted'] + $stats['updated'],
            'summaries_unchanged' => $stats['unchanged'],
        ];
    }

    /** @return array{saved:int, inserted:int, updated:int, unchanged:int} */
    private function recalculateChunk(Collection $leadSalesforceIds): array
    {
        $summaries = $leadSalesforceIds
            ->mapWithKeys(fn ($leadSalesforceId) => [(string) $leadSalesforceId => [
                'lead_salesforce_id' => (string) $leadSalesforceId,
                'total_actividades' => 0,
                'total_tasks' => 0,
                'total_events' => 0,
                'fecha_primer_contacto' => null,
                'fecha_ultima_actividad' => null,
                'primer_contacto_activity_id' => null,
                'primer_contacto_tipo' => null,
                'primer_contacto_subject' => null,
                'primer_contacto_owner_id' => null,
                'primer_contacto_owner_name' => null,
                'primer_contacto_created_by_id' => null,
                'primer_contacto_created_by_name' => null,
            ]])
            ->all();

        $activities = SalesforceActivity::query()
            ->whereIn('lead_salesforce_id', $leadSalesforceIds->all())
            ->orderBy('lead_salesforce_id')
            ->orderBy('created_date')
            ->get();

        foreach ($activities as $activity) {
            $leadSalesforceId = (string) $activity->lead_salesforce_id;

            if (! isset($summaries[$leadSalesforceId])) {
                continue;
            }

            $summaries[$leadSalesforceId]['total_actividades']++;
            $summaries[$leadSalesforceId]['total_tasks'] += $activity->activity_kind === 'Task' ? 1 : 0;
            $summaries[$leadSalesforceId]['total_events'] += $activity->activity_kind === 'Event' ? 1 : 0;
            $summaries[$leadSalesforceId]['fecha_ultima_actividad'] = $activity->created_date;

            if ($summaries[$leadSalesforceId]['fecha_primer_contacto'] === null) {
                $summaries[$leadSalesforceId]['fecha_primer_contacto'] = $activity->created_date;
                $summaries[$leadSalesforceId]['primer_contacto_activity_id'] = $activity->salesforce_id;
                $summaries[$leadSalesforceId]['primer_contacto_tipo'] = $activity->activity_kind;
                $summaries[$leadSalesforceId]['primer_contacto_subject'] = $activity->subject;
                $summaries[$leadSalesforceId]['primer_contacto_owner_id'] = $activity->owner_id;
                $summaries[$leadSalesforceId]['primer_contacto_owner_name'] = $activity->owner_name;
                $summaries[$leadSalesforceId]['primer_contacto_created_by_id'] = $activity->created_by_id;
                $summaries[$leadSalesforceId]['primer_contacto_created_by_name'] = $activity->created_by_name;
            }
        }

        $stats = $this->changedRowUpsert->persist(
            SalesforceLeadActivitySummary::class,
            array_values($summaries),
            'lead_salesforce_id',
        );

        return ['saved' => count($summaries), ...$stats];
    }
}
