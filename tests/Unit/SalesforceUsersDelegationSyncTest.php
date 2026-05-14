<?php

namespace Tests\Unit;

use App\Models\SalesforceUser;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyUsersSyncService;
use App\Services\Salesforce\SalesforceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceUsersDelegationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_delegacion_de_usuario_salesforce(): void
    {
        $client = new class extends SalesforceClient
        {
            public function __construct()
            {
            }

            public function query(string $soql): array
            {
                return [[
                    'Id' => '005-commercial',
                    'Name' => 'Comercial Madrid',
                    'Profile' => ['Name' => 'Compra/Venta'],
                    'USR_SEL_Delegacion__c' => 'HR MOTOR MADRID',
                    'IsActive' => true,
                ]];
            }
        };

        $result = (new SalesforceMonthlyUsersSyncService($client))->sync();

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('salesforce_users', [
            'salesforce_id' => '005-commercial',
            'user_delegation' => 'HR MOTOR MADRID',
        ]);
        $this->assertSame('HR MOTOR MADRID', SalesforceUser::first()->user_delegation);
    }
}
