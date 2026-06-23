<?php

namespace App\Services\Reports\CommercialCommissions\Sync;

use App\Models\SalesforceReview;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class SalesforceReviewSyncService
{
    private const SYNC_CHUNK_DAYS = 31;

    public function __construct(
        private readonly SalesforceClient $client,
    ) {
    }

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $saved = 0;
        $soqls = [];
        $seen = [];

        $chunkStart = CarbonImmutable::parse($periodStart);
        $finalEnd = CarbonImmutable::parse($periodEnd);

        while ($chunkStart->lessThan($finalEnd)) {
            $chunkEnd = $chunkStart->addDays(self::SYNC_CHUNK_DAYS)->min($finalEnd);
            $soql = $this->soql($chunkStart, $chunkEnd);
            $records = $this->client->query($soql);
            $soqls[] = $soql;

            foreach ($records as $record) {
                $salesforceId = (string) data_get($record, 'Id');

                if ($salesforceId === '' || isset($seen[$salesforceId])) {
                    continue;
                }

                $seen[$salesforceId] = true;

                SalesforceReview::updateOrCreate(
                    ['salesforce_id' => $salesforceId],
                    [
                        'created_date' => data_get($record, 'CreatedDate'),
                        'owner_id' => data_get($record, 'OwnerId'),
                        'owner_name' => data_get($record, 'Owner.Name'),
                        'opportunity_salesforce_id' => data_get($record, 'RES_BUS_Oportunidad__c'),
                        'opportunity_name' => data_get($record, 'RES_BUS_Oportunidad__r.Name'),
                        'opportunity_owner_id' => data_get($record, 'RES_BUS_Oportunidad__r.OwnerId'),
                        'opportunity_owner_name' => data_get($record, 'RES_BUS_Oportunidad__r.Owner.Name'),
                        'opportunity_record_type_name' => data_get($record, 'RES_BUS_Oportunidad__r.RecordType.Name'),
                        'opportunity_cv_signed_date' => data_get($record, 'RES_BUS_Oportunidad__r.Fecha_firma_contrato__c'),
                        'raw_payload' => $record,
                    ]
                );

                $saved++;
            }

            $chunkStart = $chunkEnd;
        }

        return [
            'soql' => implode("\n\n-- chunk --\n\n", $soqls),
            'queried' => count($seen),
            'saved' => $saved,
        ];
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $startDateTime = $this->soqlDateTime($periodStart);
        $endDateTime = $this->soqlDateTime($periodEnd);

        return <<<SOQL
SELECT
    Id,
    CreatedDate,
    OwnerId,
    Owner.Name,
    RES_BUS_Oportunidad__c,
    RES_BUS_Oportunidad__r.Name,
    RES_BUS_Oportunidad__r.OwnerId,
    RES_BUS_Oportunidad__r.Owner.Name,
    RES_BUS_Oportunidad__r.RecordType.Name,
    RES_BUS_Oportunidad__r.Fecha_firma_contrato__c
FROM Resena__c
WHERE
    CreatedDate >= {$startDateTime}
    AND CreatedDate < {$endDateTime}
SOQL;
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
