<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceUser;
use App\Services\Salesforce\SalesforceClient;

class SalesforceMonthlyUsersSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
    ) {
    }

    public function sync(): array
    {
        $soql = $this->soql();
        $records = $this->client->query($soql);
        $saved = 0;

        foreach ($records as $record) {
            if (blank(data_get($record, 'Id'))) {
                continue;
            }

            SalesforceUser::updateOrCreate(
                ['salesforce_id' => data_get($record, 'Id')],
                [
                    'name' => data_get($record, 'Name'),
                    'profile_name' => data_get($record, 'Profile.Name'),
                    'is_active' => (bool) data_get($record, 'IsActive', true),
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

    public function soql(): string
    {
        return <<<'SOQL'
SELECT
    Id,
    Name,
    Profile.Name,
    IsActive
FROM User
WHERE
    IsActive = true
    AND (
        Profile.Name = 'Comerciales Partner Community'
        OR Profile.Name = 'Compra/Venta'
    )
SOQL;
    }
}
