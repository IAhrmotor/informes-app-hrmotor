<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceActivity;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class SalesforceMonthlyActivitiesSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
    ) {
    }

    public function syncTasks(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        return $this->syncActivityKind('Task', $this->tasksSoql($periodStart, $periodEnd));
    }

    public function syncEvents(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        return $this->syncActivityKind('Event', $this->eventsSoql($periodStart, $periodEnd));
    }

    public function tasksSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
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

    public function eventsSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
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

    private function syncActivityKind(string $kind, string $soql): array
    {
        $records = $this->client->query($soql);
        $saved = 0;

        foreach ($records as $record) {
            if (blank(data_get($record, 'Id')) || blank(data_get($record, 'WhoId'))) {
                continue;
            }

            SalesforceActivity::updateOrCreate(
                ['salesforce_id' => data_get($record, 'Id')],
                [
                    'lead_salesforce_id' => data_get($record, 'WhoId'),
                    'activity_kind' => $kind,
                    'owner_id' => data_get($record, 'OwnerId'),
                    'owner_name' => data_get($record, 'Owner.Name'),
                    'created_by_id' => data_get($record, 'CreatedById'),
                    'created_by_name' => data_get($record, 'CreatedBy.Name'),
                    'created_date' => $this->parseDateTime(data_get($record, 'CreatedDate')),
                    'activity_date' => data_get($record, 'ActivityDate'),
                    'subject' => data_get($record, 'Subject'),
                    'type' => data_get($record, 'Type'),
                    'status' => data_get($record, 'Status'),
                    'raw_payload' => $record,
                ]
            );

            $saved++;
        }

        return [
            'soql' => $soql,
            'queried' => count($records),
            'saved' => $saved,
        ];
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
