<?php

namespace Tests\Unit;

use App\Models\SalesforceLead;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyLeadsSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceMonthlyLeadsSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_leads_de_salesforce_con_relaciones_anidadas(): void
    {
        $client = new class extends SalesforceClient
        {
            public string $lastSoql = '';

            public function __construct()
            {
            }

            public function query(string $soql): array
            {
                $this->lastSoql = $soql;

                return [
                    [
                        'Id' => '00Q1',
                        'Name' => 'Lead Uno',
                        'CreatedDate' => '2026-05-01T10:00:00.000+0000',
                        'LastActivityDate' => '2026-05-02',
                        'Status' => 'Convertido',
                        'OwnerId' => '005-owner-1',
                        'Owner' => ['Name' => 'Owner Uno'],
                        'Persona_que_trabaj__c' => '005-worker-1',
                        'Persona_que_trabaj__r' => ['Name' => 'Worker Uno'],
                        'Propietario_cuando_se_descarto__c' => null,
                        'Propietario_cuando_se_descarto__r' => null,
                        'Fecha_Asignacion__c' => '2026-05-01T10:05:00.000+0000',
                        'LEA_SEL_Fuente_Origen__c' => 'Fuente',
                        'LEA_SEL_Medio_Origen__c' => 'Medio',
                        'Portal_Text__c' => 'Web',
                        'Delegacion_Encargada_Text__c' => 'Madrid',
                    ],
                    [
                        'Id' => '00Q2',
                        'Name' => 'Lead Dos',
                        'CreatedDate' => '2026-05-03T11:00:00.000+0000',
                        'LastActivityDate' => null,
                        'Status' => 'Potencial',
                        'OwnerId' => '005-owner-2',
                        'Owner' => ['Name' => 'Owner Dos'],
                        'Persona_que_trabaj__c' => null,
                        'Persona_que_trabaj__r' => null,
                        'Propietario_cuando_se_descarto__c' => null,
                        'Propietario_cuando_se_descarto__r' => null,
                        'Fecha_Asignacion__c' => null,
                        'LEA_SEL_Fuente_Origen__c' => null,
                        'LEA_SEL_Medio_Origen__c' => null,
                        'Portal_Text__c' => 'Meta',
                        'Delegacion_Encargada_Text__c' => null,
                    ],
                ];
            }
        };

        $service = new SalesforceMonthlyLeadsSyncService($client);
        $result = $service->sync(
            CarbonImmutable::parse('2026-03-14 13:37:27', 'UTC'),
            CarbonImmutable::parse('2026-05-13 13:37:27', 'UTC'),
        );

        $this->assertSame(2, $result['queried']);
        $this->assertSame(2, $result['saved']);
        $this->assertStringContainsString('CreatedDate >= 2026-03-14T13:37:27Z', $result['soql']);
        $this->assertStringContainsString('CreatedDate < 2026-05-13T13:37:27Z', $result['soql']);

        $this->assertSame(2, SalesforceLead::query()->count());
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q1',
            'owner_name' => 'Owner Uno',
            'persona_que_trabajo_name' => 'Worker Uno',
            'portal_text' => 'Web',
        ]);
    }
}
