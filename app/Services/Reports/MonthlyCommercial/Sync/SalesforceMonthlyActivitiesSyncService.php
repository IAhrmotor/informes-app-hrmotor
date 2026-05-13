<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceActivity;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

class SalesforceMonthlyActivitiesSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
    ) {
    }

    public function syncTasks(CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        return $this->syncActivityKind('Task', $this->tasksSoql($periodStart, $periodEnd));
    }

    public function syncEvents(CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        return $this->syncActivityKind('Event', $this->eventsSoql($periodStart, $periodEnd));
    }

    private function syncActivityKind(string $kind, string $soql): int
    {
        $records = $this->client->query($soql);
        $synced = 0;

        foreach ($records as $record) {
            if (blank(Arr::get($record, 'Id')) || blank(Arr::get($record, 'WhoId'))) {
                continue;
            }

            SalesforceActivity::updateOrCreate(
                ['salesforce_id' => Arr::get($record, 'Id')],
                [
                    'lead_salesforce_id' => Arr::get($record, 'WhoId'),
                    'activity_kind' => $kind,
                    'owner_id' => Arr::get($record, 'OwnerId'),
                    'owner_name' => Arr::get($record, 'Owner.Name'),
                    'created_by_id' => Arr::get($record, 'CreatedById'),
                    'created_by_name' => Arr::get($record, 'CreatedBy.Name'),
                    'created_date' => $this->parseDateTime(Arr::get($record, 'CreatedDate')),
                    'activity_date' => Arr::get($record, 'ActivityDate'),
                    'subject' => Arr::get($record, 'Subject'),
                    'type' => Arr::get($record, 'Type'),
                    'status' => Arr::get($record, 'Status'),
                    'raw_payload' => $record,
                ]
            );

            $synced++;
        }

        return $synced;
    }

    private function tasksSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $start = $this->soqlDateTime($periodStart);
        $end = $this->soqlDateTime($periodEnd);

        return <<<SOQL
SELECT
    Id,
    WhoId,
    OwnerId,
    Owner.Name,
    CreatedById,
    CreatedBy.Name,
    CreatedDate,
    ActivityDate,
    Subject,
    Type,
    Status
FROM Task
WHERE
    WhoId IN (
        SELECT Id
        FROM Lead
        WHERE
            IsDeleted = false
            AND CreatedDate >= {$start}
            AND CreatedDate < {$end}
    )
    AND CreatedDate >= {$start}
    AND CreatedDate < {$end}
SOQL;
    }

    private function eventsSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $start = $this->soqlDateTime($periodStart);
        $end = $this->soqlDateTime($periodEnd);

        return <<<SOQL
SELECT
    Id,
    WhoId,
    OwnerId,
    Owner.Name,
    CreatedById,
    CreatedBy.Name,
    CreatedDate,
    ActivityDate,
    Subject,
    Type
FROM Event
WHERE
    WhoId IN (
        SELECT Id
        FROM Lead
        WHERE
            IsDeleted = false
            AND CreatedDate >= {$start}
            AND CreatedDate < {$end}
    )
    AND CreatedDate >= {$start}
    AND CreatedDate < {$end}
SOQL;
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
