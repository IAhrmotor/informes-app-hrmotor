<?php

namespace Tests\Unit;

use App\Models\SalesforceLead;
use App\Services\Reports\Leads\LeadPortalResolver;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
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

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->lastSoql = $soql;

                return [
                    [
                        'Id' => '00Q1',
                        'Name' => 'Lead Uno',
                        'CreatedDate' => '2026-05-01T10:00:00.000+0000',
                        'LastModifiedDate' => '2026-05-12T10:00:00.000+0000',
                        'LastActivityDate' => '2026-05-02',
                        'Status' => 'Convertido',
                        'RecordType' => ['Name' => 'Tasacion'],
                        'OwnerId' => '005-owner-1',
                        'Owner' => ['Name' => 'Owner Uno'],
                        'Captador_de_cita__c' => '005-setter-1',
                        'Captador_de_cita__r' => ['Name' => 'Maria Vidal'],
                        'Persona_que_trabaj__c' => '005-worker-1',
                        'Persona_que_trabaj__r' => ['Name' => 'Worker Uno'],
                        'Propietario_cuando_se_descarto__c' => null,
                        'Propietario_cuando_se_descarto__r' => null,
                        'Fecha_Asignacion__c' => '2026-05-01T10:05:00.000+0000',
                        'Fecha_captador__c' => '2026-05-01',
                        'Cita_llamada__c' => true,
                        'Cita_Tienda__c' => false,
                        'Acudi_a_la_cita__c' => 'ACUDIO',
                        'Comercial_que_atiende_en_tienda__c' => '005-store-1',
                        'Comercial_que_atiende_en_tienda__r' => ['Name' => 'Comercial Tienda 1'],
                        'Estado_del_candidato_formula__c' => 'Citado',
                        'LEA_SEL_Fuente_Origen__c' => 'Fuente',
                        'LEA_SEL_Medio_Origen__c' => 'Medio',
                        'Portal_Text__c' => 'Web',
                        'Delegacion_Encargada_Text__c' => 'Madrid',
                    ],
                    [
                        'Id' => '00Q2',
                        'Name' => 'Lead Dos',
                        'CreatedDate' => '2026-05-03T11:00:00.000+0000',
                        'LastModifiedDate' => '2026-05-12T11:00:00.000+0000',
                        'LastActivityDate' => null,
                        'Status' => 'Potencial',
                        'RecordType' => ['Name' => 'Venta con cambio'],
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

            public function queryAll(string $soql): array
            {
                return [];
            }

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield $this->query($soql);
            }
        };

        $service = new SalesforceMonthlyLeadsSyncService(
            $client,
            new LeadRecordTypeNormalizer,
            new LeadPortalResolver,
        );
        $result = $service->sync(
            CarbonImmutable::parse('2026-03-14 13:37:27', 'UTC'),
            CarbonImmutable::parse('2026-05-13 13:37:27', 'UTC'),
        );

        $this->assertSame(2, $result['queried']);
        $this->assertSame(2, $result['saved']);
        $this->assertStringContainsString('RecordType.Name', $result['soql']);
        $this->assertStringContainsString('Captador_de_cita__c', $result['soql']);
        $this->assertStringContainsString('CreatedDate >= 2026-03-14T13:37:27Z', $result['soql']);
        $this->assertStringContainsString('CreatedDate < 2026-05-13T13:37:27Z', $result['soql']);
        $this->assertStringContainsString('Fecha_captador__c >= 2026-03-14', $result['soql']);
        $this->assertStringContainsString('Fecha_captador__c < 2026-05-13', $result['soql']);
        $this->assertStringContainsString('LastModifiedDate >= 2026-03-14T13:37:27Z', $result['soql']);

        $this->assertSame(2, SalesforceLead::query()->count());
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q1',
            'owner_name' => 'Owner Uno',
            'record_type_name' => 'Tasacion',
            'record_type_normalized' => 'tasacion',
            'appointment_setter_name' => 'Maria Vidal',
            'appointment_call' => true,
            'appointment_attended_status' => 'ACUDIO',
            'medio_origen' => 'Medio',
            'persona_que_trabajo_name' => 'Worker Uno',
            'portal_text' => 'Web',
            'resolved_portal' => 'Web',
            'portal_resolution_source' => 'Portal_Text__c',
            'is_deleted' => false,
        ]);
    }

    public function test_actualiza_leads_antiguos_modificados_y_marca_eliminados(): void
    {
        SalesforceLead::create([
            'salesforce_id' => '00Q-OLD',
            'name' => 'Lead antiguo antes del cambio',
            'created_date' => '2025-01-01 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'record_type_normalized' => 'venta',
            'portal_text' => 'Web',
            'resolved_portal' => 'Web',
            'synced_at' => '2026-05-10 09:00:00',
            'is_deleted' => false,
        ]);

        SalesforceLead::create([
            'salesforce_id' => '00Q-HARD-DELETED',
            'name' => 'Hard deleted',
            'created_date' => '2026-05-12 09:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'synced_at' => '2026-05-10 09:00:00',
            'is_deleted' => false,
        ]);

        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                return [[
                    'Id' => '00Q-OLD',
                    'Name' => 'Lead antiguo modificado',
                    'CreatedDate' => '2025-01-01T10:00:00.000+0000',
                    'LastModifiedDate' => '2026-05-12T10:00:00.000+0000',
                    'Status' => 'Convertido',
                    'RecordType' => ['Name' => 'Tasación'],
                    'Portal_Text__c' => 'Coches.net',
                ]];
            }

            public function queryAll(string $soql): array
            {
                return [[
                    'Id' => '00Q-DELETED',
                    'Name' => 'Eliminado',
                    'CreatedDate' => '2026-01-01T10:00:00.000+0000',
                    'IsDeleted' => true,
                    'MasterRecordId' => '00Q-MASTER',
                    'LastModifiedDate' => '2026-05-12T11:00:00.000+0000',
                    'Status' => 'Potencial',
                    'RecordType' => ['Name' => 'Venta'],
                    'Medio_Nuevo__c' => 'Llamada',
                    'Fuente_Nuevo__c' => 'Coches.net Coche Nuevo',
                    'Portal_Text__c' => 'Coches.net',
                ]];
            }

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield $this->query($soql);
            }
        };

        $service = new SalesforceMonthlyLeadsSyncService(
            $client,
            new LeadRecordTypeNormalizer,
            new LeadPortalResolver,
        );
        $result = $service->sync(
            CarbonImmutable::parse('2026-05-11 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-05-13 00:00:00', 'UTC'),
        );

        $this->assertSame(2, $result['deleted']);
        $this->assertSame(1, $result['deleted_query_all']);
        $this->assertSame(1, $result['deleted_missing']);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-OLD',
            'status' => 'Convertido',
            'record_type_normalized' => 'tasacion',
            'resolved_portal' => 'Coches.net',
        ]);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-DELETED',
            'is_deleted' => true,
            'resolved_portal' => 'Coches.net Coche Nuevo',
            'deletion_detection_source' => 'query_all',
            'salesforce_master_record_id' => '00Q-MASTER',
        ]);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-HARD-DELETED',
            'is_deleted' => true,
            'deletion_detection_source' => 'missing_from_salesforce',
        ]);
    }
}
