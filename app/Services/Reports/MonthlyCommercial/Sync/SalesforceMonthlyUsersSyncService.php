<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceUser;
use App\Services\Salesforce\SalesforceClient;
use Illuminate\Support\Arr;

class SalesforceMonthlyUsersSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
    ) {
    }

    public function sync(): int
    {
        $records = $this->client->query(<<<'SOQL'
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
SOQL);

        $synced = 0;

        foreach ($records as $record) {
            if (blank(Arr::get($record, 'Id'))) {
                continue;
            }

            SalesforceUser::updateOrCreate(
                ['salesforce_id' => Arr::get($record, 'Id')],
                [
                    'name' => Arr::get($record, 'Name'),
                    'profile_name' => Arr::get($record, 'Profile.Name'),
                    'is_active' => (bool) Arr::get($record, 'IsActive', true),
                    'raw_payload' => $record,
                ]
            );

            $synced++;
        }

        return $synced;
    }
}
