<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceActivity;
use App\Models\SalesforceLead;
use App\Models\SalesforceLeadActivitySummary;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SalesforceLeadActivitySummaryService
{
    public function recalculateForPeriod(CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        $leadIds = SalesforceLead::query()
            ->where('created_date', '>=', $periodStart)
            ->where('created_date', '<', $periodEnd)
            ->pluck('salesforce_id');

        return $this->recalculate($leadIds);
    }

    public function recalculate(iterable $leadSalesforceIds): int
    {
        $count = 0;

        collect($leadSalesforceIds)
            ->filter()
            ->unique()
            ->values()
            ->chunk(500)
            ->each(function (Collection $chunk) use (&$count): void {
                foreach ($chunk as $leadSalesforceId) {
                    $this->recalculateLead((string) $leadSalesforceId);
                    $count++;
                }
            });

        return $count;
    }

    private function recalculateLead(string $leadSalesforceId): void
    {
        $activities = SalesforceActivity::query()
            ->where('lead_salesforce_id', $leadSalesforceId)
            ->orderBy('created_date')
            ->get();

        $first = $activities->first();
        $last = $activities->sortByDesc('created_date')->first();

        SalesforceLeadActivitySummary::updateOrCreate(
            ['lead_salesforce_id' => $leadSalesforceId],
            [
                'total_actividades' => $activities->count(),
                'total_tasks' => $activities->where('activity_kind', 'Task')->count(),
                'total_events' => $activities->where('activity_kind', 'Event')->count(),
                'fecha_primer_contacto' => $first?->created_date,
                'fecha_ultima_actividad' => $last?->created_date,
                'primer_contacto_activity_id' => $first?->salesforce_id,
                'primer_contacto_tipo' => $first?->activity_kind,
                'primer_contacto_subject' => $first?->subject,
                'primer_contacto_owner_id' => $first?->owner_id,
                'primer_contacto_owner_name' => $first?->owner_name,
                'primer_contacto_created_by_id' => $first?->created_by_id,
                'primer_contacto_created_by_name' => $first?->created_by_name,
            ]
        );
    }
}
