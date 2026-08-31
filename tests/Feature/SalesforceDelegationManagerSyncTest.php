<?php

namespace Tests\Feature;

use App\Models\SalesforceDelegationManagerHistory;
use App\Services\Reports\CommercialCommissions\Sync\SalesforceDelegationManagerSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SalesforceDelegationManagerSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_uses_lookup_identity_and_name_without_local_user_or_per_delegation_queries(): void
    {
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->once()->with(Mockery::on(fn (string $soql): bool => str_contains($soql, 'FROM Delegacion__c')))->andReturn([
            ['Id' => 'a01000000000001', 'Name' => 'Alicante', 'DEL_BUS_Jefe_Tienda__c' => '005000000000999', 'DEL_BUS_Jefe_Tienda__r' => ['Name' => 'Kosta']],
            ['Id' => 'a01000000000002', 'Name' => 'Paterna', 'DEL_BUS_Jefe_Tienda__c' => '005000000000999', 'DEL_BUS_Jefe_Tienda__r' => ['Name' => 'Kosta']],
            ['Id' => 'a01000000000003', 'Name' => 'Elche', 'DEL_BUS_Jefe_Tienda__c' => null, 'DEL_BUS_Jefe_Tienda__r' => null],
        ]);
        $client->shouldReceive('queryAll')->once()->andReturn([]);
        $client->shouldNotReceive('query')->with(Mockery::on(fn (string $soql): bool => str_contains($soql, 'FROM User')));
        $this->app->instance(SalesforceClient::class, $client);

        $result = app(SalesforceDelegationManagerSyncService::class)->sync(CarbonImmutable::parse('2026-07-01'));

        $this->assertSame(3, $result['delegations']);
        $this->assertDatabaseHas('salesforce_delegation_manager_history', [
            'delegation_salesforce_id' => 'a01000000000001',
            'manager_salesforce_user_id' => '005000000000999',
            'manager_name' => 'Kosta',
        ]);
        $this->assertDatabaseHas('salesforce_delegation_manager_history', [
            'delegation_salesforce_id' => 'a01000000000003',
            'manager_salesforce_user_id' => null,
            'manager_name' => null,
        ]);
    }

    public function test_historical_manager_names_are_resolved_with_one_batch_user_query(): void
    {
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->once()->with(Mockery::on(fn (string $soql): bool => str_contains($soql, 'FROM Delegacion__c')))->andReturn([
            ['Id' => 'a01000000000001', 'Name' => 'Alicante', 'DEL_BUS_Jefe_Tienda__c' => '005000000000999', 'DEL_BUS_Jefe_Tienda__r' => ['Name' => 'Kosta']],
        ]);
        $client->shouldReceive('queryAll')->once()->andReturn([
            ['Id' => '017000000000001', 'ParentId' => 'a01000000000001', 'CreatedDate' => '2026-07-20T10:00:00Z', 'OldValue' => '005000000000111', 'NewValue' => '005000000000999'],
        ]);
        $client->shouldReceive('query')->once()->with(Mockery::on(fn (string $soql): bool => str_contains($soql, 'FROM User') && str_contains($soql, '005000000000111')))->andReturn([
            ['Id' => '005000000000111', 'Name' => 'Responsable anterior'],
        ]);
        $this->app->instance(SalesforceClient::class, $client);

        app(SalesforceDelegationManagerSyncService::class)->sync(CarbonImmutable::parse('2026-07-01'));

        $this->assertDatabaseHas('salesforce_delegation_manager_history', [
            'source_key' => 'history:017000000000001',
            'manager_salesforce_user_id' => '005000000000999',
            'manager_name' => 'Kosta',
        ]);
        $this->assertSame(2, SalesforceDelegationManagerHistory::query()->count());
    }
}
