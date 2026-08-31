<?php

namespace App\Services\Reports\CommercialCommissions\Sync;

use App\Models\SalesforceDelegationManagerHistory;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;

class SalesforceDelegationManagerSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
    ) {}

    public function sync(CarbonImmutable $from): array
    {
        $current = collect($this->client->query('SELECT Id, Name, DEL_BUS_Jefe_Tienda__c, DEL_BUS_Jefe_Tienda__r.Name FROM Delegacion__c'));
        $history = collect($this->client->queryAll("SELECT Id, ParentId, CreatedDate, OldValue, NewValue FROM Delegacion__History WHERE Field = 'DEL_BUS_Jefe_Tienda__c' AND CreatedDate >= {$from->utc()->format('Y-m-d\\TH:i:s\\Z')} ORDER BY CreatedDate ASC"));
        $delegations = $current->keyBy('Id');
        $users = $current
            ->filter(fn (array $row): bool => filled($row['DEL_BUS_Jefe_Tienda__c'] ?? null))
            ->mapWithKeys(fn (array $row): array => [(string) $row['DEL_BUS_Jefe_Tienda__c'] => [
                'Id' => (string) $row['DEL_BUS_Jefe_Tienda__c'],
                'Name' => data_get($row, 'DEL_BUS_Jefe_Tienda__r.Name'),
            ]]);
        $missingUserIds = $history
            ->flatMap(fn (array $row): array => [(string) ($row['OldValue'] ?? ''), (string) ($row['NewValue'] ?? '')])
            ->merge($current
                ->filter(fn (array $row): bool => filled($row['DEL_BUS_Jefe_Tienda__c'] ?? null) && blank(data_get($row, 'DEL_BUS_Jefe_Tienda__r.Name')))
                ->pluck('DEL_BUS_Jefe_Tienda__c'))
            ->filter()
            ->unique()
            ->reject(fn (string $id): bool => filled(data_get($users->get($id), 'Name')))
            ->values();

        if ($missingUserIds->isNotEmpty()) {
            $historicalUsers = collect($this->client->query('SELECT Id, Name FROM User WHERE Id IN ('.$missingUserIds->map(fn (string $id): string => "'".str_replace("'", "\\'", $id)."'")->implode(',').')'))->keyBy('Id');
            $users = $users->merge($historicalUsers);
        }
        $saved = 0;

        foreach ($history->groupBy('ParentId') as $delegationId => $events) {
            $delegation = $delegations->get($delegationId, []);
            $name = (string) ($delegation['Name'] ?? $delegationId);
            // OldValue demuestra la transición, pero no desde cuándo era efectivo.
            // No se extiende retrospectivamente hasta --from sin otra evidencia.
            $points = $events->map(fn (array $event): array => [
                'source_key' => 'history:'.$event['Id'],
                'manager_id' => (string) ($event['NewValue'] ?? ''),
                'effective_at' => CarbonImmutable::parse($event['CreatedDate']),
                'source' => 'field_history',
                'reference' => 'Delegacion__History:'.$event['Id'],
            ])->values();
            foreach ($points as $index => $point) {
                $coverageTo = data_get($points->get($index + 1), 'effective_at', CarbonImmutable::now());
                $saved += $this->store($point['source_key'], $delegationId, $name, $point['manager_id'], $point['effective_at'], $coverageTo, $point['source'], true, $point['reference'], $users);
            }
        }

        foreach ($current as $delegation) {
            $observed = CarbonImmutable::now()->startOfDay();
            $saved += $this->store('current:'.$delegation['Id'].':'.$observed->toDateString(), $delegation['Id'], $delegation['Name'], (string) ($delegation['DEL_BUS_Jefe_Tienda__c'] ?? ''), $observed, $observed->addDay(), 'daily_observation', true, 'Delegacion__c:daily-read', $users);
        }

        return ['delegations' => $current->count(), 'history_events' => $history->count(), 'rows_saved' => $saved];
    }

    private function store(string $sourceKey, string $delegationId, string $name, string $managerId, CarbonImmutable $coverageFrom, ?CarbonImmutable $coverageTo, string $source, bool $verified, string $reference, $users): int
    {
        SalesforceDelegationManagerHistory::query()->updateOrCreate(['source_key' => $sourceKey], [
            'delegation_salesforce_id' => $delegationId,
            'delegation_name' => $name,
            'delegation_key' => $this->formulaConfig->delegationKey($name),
            'manager_salesforce_user_id' => $managerId !== '' ? $managerId : null,
            'manager_name' => $managerId !== '' ? data_get($users->get($managerId), 'Name') : null,
            'effective_at' => $coverageFrom,
            'coverage_from' => $coverageFrom,
            'coverage_to' => $coverageTo,
            'observed_at' => now(),
            'source' => $source,
            'evidence_reference' => $reference,
            'history_verified' => $verified,
        ]);

        return 1;
    }
}
