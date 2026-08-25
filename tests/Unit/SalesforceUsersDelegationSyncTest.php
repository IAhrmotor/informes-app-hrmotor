<?php

namespace Tests\Unit;

use App\Models\SalesforceUser;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyUsersSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceUsersDelegationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_delegacion_de_usuario_salesforce(): void
    {
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                return [[
                    'Id' => '005-commercial',
                    'Name' => 'Comercial Madrid',
                    'Email' => 'comercial.madrid@hrmotor.com',
                    'Profile' => ['Name' => 'Compra/Venta'],
                    'USR_SEL_Delegacion__c' => 'HR MOTOR MADRID',
                    'Comision_Tasador__c' => true,
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
        $this->assertSame('comercial.madrid@hrmotor.com', SalesforceUser::first()->email);
        $this->assertTrue(SalesforceUser::first()->commission_appraiser);
    }

    public function test_no_reescribe_usuario_identico_y_actualiza_un_cambio_real(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $record = [
                'Id' => '005-stable',
                'Name' => 'Comercial estable',
                'Email' => 'stable@example.test',
                'Profile' => ['Name' => 'Compra/Venta'],
                'USR_SEL_Delegacion__c' => 'MADRID',
                'Comision_Tasador__c' => false,
                'IsActive' => true,
                'attributes' => ['type' => 'User', 'url' => '/users/005-stable'],
                'OptionalNull' => null,
            ];

            public function __construct() {}

            public function query(string $soql): array
            {
                return [$this->record];
            }
        };

        $service = new SalesforceMonthlyUsersSyncService($client);
        CarbonImmutable::setTestNow('2026-05-03 10:00:00');
        $first = $service->sync();
        $originalUpdatedAt = SalesforceUser::where('salesforce_id', '005-stable')->value('updated_at');

        CarbonImmutable::setTestNow('2026-05-03 10:15:00');
        $client->record['attributes'] = ['url' => '/users/005-stable', 'type' => 'User'];
        $client->record['OptionalNull'] = '';
        $second = $service->sync();

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['updated']);
        $this->assertEquals($originalUpdatedAt, SalesforceUser::where('salesforce_id', '005-stable')->value('updated_at'));

        $client->record['USR_SEL_Delegacion__c'] = 'ALCOBENDAS';
        CarbonImmutable::setTestNow('2026-05-03 10:30:00');
        $third = $service->sync();

        $this->assertSame(1, $third['updated']);
        $this->assertSame('ALCOBENDAS', SalesforceUser::where('salesforce_id', '005-stable')->value('user_delegation'));
        $this->assertNotEquals($originalUpdatedAt, SalesforceUser::where('salesforce_id', '005-stable')->value('updated_at'));
    }
}
