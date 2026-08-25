<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceUser;
use App\Services\Salesforce\SalesforceClient;

class SalesforceMonthlyUsersSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
        private readonly ChangedRowUpsert $changedRowUpsert = new ChangedRowUpsert,
    ) {}

    public function sync(): array
    {
        $soql = $this->soql();
        $records = $this->client->query($soql);
        $values = [];

        foreach ($records as $record) {
            if (blank(data_get($record, 'Id'))) {
                continue;
            }

            $values[] = [
                'salesforce_id' => data_get($record, 'Id'),
                'name' => data_get($record, 'Name'),
                'email' => data_get($record, 'Email'),
                'profile_name' => data_get($record, 'Profile.Name'),
                'user_delegation' => data_get($record, 'USR_SEL_Delegacion__c'),
                'is_active' => (bool) data_get($record, 'IsActive', true),
                'commission_appraiser' => (bool) data_get($record, 'Comision_Tasador__c', false),
                'raw_payload' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach (array_chunk($values, 500) as $chunk) {
            $chunkStats = $this->changedRowUpsert->persist(SalesforceUser::class, $chunk, 'salesforce_id');
            foreach ($stats as $key => $value) {
                $stats[$key] += $chunkStats[$key];
            }
        }

        return [
            'soql' => $soql,
            'queried' => count($records),
            'saved' => count($values),
            ...$stats,
        ];
    }

    public function soql(): string
    {
        return <<<'SOQL'
SELECT
    Id,
    Name,
    Email,
    Profile.Name,
    USR_SEL_Delegacion__c,
    Comision_Tasador__c,
    IsActive
FROM User
WHERE
    IsActive = true
    AND (
        Profile.Name = 'Comerciales Partner Community'
        OR Profile.Name = 'Compra/Venta'
        OR Comision_Tasador__c = true
    )
SOQL;
    }
}
