<?php

namespace Tests\Unit;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceOpportunitySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_oportunidades_y_resuelve_portal_desde_salesforce_o_lead(): void
    {
        $client = new class extends SalesforceClient
        {
            public function __construct()
            {
            }

            public function query(string $soql): array
            {
                if (str_contains($soql, 'FROM Lead')) {
                    return [
                        [
                            'Id' => '00Q-lead-1',
                            'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                            'Phone' => '600 000 001',
                            'MobilePhone' => null,
                            'Email' => 'cliente@example.com',
                            'Portal_Text__c' => 'Meta',
                        ],
                        [
                            'Id' => '00Q-lead-2',
                            'CreatedDate' => '2026-05-11T10:00:00.000+0000',
                            'Phone' => '600000002',
                            'MobilePhone' => null,
                            'Email' => 'expo@example.com',
                            'Portal_Text__c' => 'Google Maps',
                        ],
                    ];
                }

                return [
                    [
                        'Id' => '006-opportunity-1',
                        'Name' => 'Venta directa',
                        'CreatedDate' => '2026-05-01T10:00:00.000+0000',
                        'CloseDate' => '2026-05-31',
                        'Amount' => 12500.50,
                        'OPO_FOR_Importe_total__c' => 13000.75,
                        'StageName' => 'Reserva',
                        'RecordType' => ['Name' => 'Venta'],
                        'OwnerId' => '005-owner-1',
                        'Owner' => ['Name' => 'Comercial Uno', 'USR_SEL_Delegacion__c' => 'Alcobendas'],
                        'AccountId' => '001-1',
                        'Account' => [
                            'Name' => 'Cuenta 1',
                            'Phone' => '600000000',
                            'PersonEmail' => null,
                            'AC_C_EMA_email__c' => null,
                        ],
                        'Portal__c' => 'Web',
                        'OPO_CAS_Reserva__c' => true,
                        'OPO_FEC_Fecha_de_reserva__c' => '2026-05-02',
                        'OPO_CAS_Contrato_CV_firmado__c' => false,
                        'Fecha_firma_contrato__c' => null,
                        'Tienda_de_entrega__c' => 'HR MOTOR ALICANTE',
                        'Gestion_de_venta__c' => false,
                        'OPP_BUS_Vehiculo_de_interes__r' => [
                            'PRO_DIV_Precio_de_venta__c' => 12000,
                            'PRO_DIV_Precio_de_compra__c' => 9000,
                            'Procedencia_de_compra__c' => 'Compra directa',
                            'PRO_FEC_Fecha_compra__c' => '2026-05-03',
                            'Comprador_oportunidad__c' => '005-buyer-1',
                            'Comprador_oportunidad__r' => ['Name' => 'Comprador Uno'],
                        ],
                    ],
                    [
                        'Id' => '006-opportunity-2',
                        'Name' => 'Venta reconstruida',
                        'CreatedDate' => '2026-05-03T10:00:00.000+0000',
                        'Amount' => 18000,
                        'OPO_FOR_Importe_total__c' => 18100,
                        'StageName' => 'Contrato',
                        'RecordType' => ['Name' => 'Cambio'],
                        'OwnerId' => '005-owner-2',
                        'Owner' => ['Name' => 'Comercial Dos', 'USR_SEL_Delegacion__c' => 'Sant Boi'],
                        'AccountId' => '001-2',
                        'Account' => [
                            'Phone' => '+34 600 000 001',
                            'PersonEmail' => 'cliente@example.com',
                            'AC_C_EMA_email__c' => null,
                        ],
                        'Portal__c' => null,
                        'OPO_CAS_Reserva__c' => true,
                        'OPO_FEC_Fecha_de_reserva__c' => '2026-05-04',
                        'OPO_CAS_Contrato_CV_firmado__c' => true,
                        'Fecha_firma_contrato__c' => '2026-05-05',
                        'Gestion_de_venta__c' => true,
                    ],
                    [
                        'Id' => '006-opportunity-3',
                        'Name' => 'Exposicion con alternativa',
                        'CreatedDate' => '2026-05-06T10:00:00.000+0000',
                        'Amount' => 9000,
                        'OPO_FOR_Importe_total__c' => 0,
                        'StageName' => 'Cerrada Perdida',
                        'RecordType' => ['Name' => 'Tasacion'],
                        'OwnerId' => '005-owner-3',
                        'Owner' => ['Name' => 'Comercial Tres', 'USR_SEL_Delegacion__c' => 'Bilbao'],
                        'AccountId' => '001-3',
                        'Account' => [
                            'Phone' => '600000002',
                            'PersonEmail' => 'expo@example.com',
                            'AC_C_EMA_email__c' => null,
                        ],
                        'Portal__c' => 'Exposición',
                        'OPO_CAS_Reserva__c' => true,
                        'OPO_CAS_Contrato_CV_firmado__c' => true,
                        'Gestion_de_venta__c' => false,
                    ],
                ];
            }
        };

        $service = new SalesforceOpportunitySyncService($client, app(OpportunityPortalNormalizer::class));
        $result = $service->sync(
            CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'),
        );

        $this->assertSame(3, $result['queried']);
        $this->assertSame(3, $result['saved']);
        $this->assertStringContainsString('FROM Opportunity', $result['soql']);
        $this->assertStringContainsString('Amount', $result['soql']);
        $this->assertStringContainsString('OPO_FOR_Importe_total__c', $result['soql']);
        $this->assertStringContainsString('OPO_FEC_Fecha_de_reserva__c', $result['soql']);
        $this->assertStringContainsString('Fecha_firma_contrato__c', $result['soql']);
        $this->assertStringContainsString('Tienda_de_entrega__c', $result['soql']);
        $this->assertStringContainsString('Gestion_de_venta__c', $result['soql']);
        $this->assertStringContainsString('PRO_DIV_Precio_de_venta__c', $result['soql']);
        $this->assertStringContainsString('Procedencia_de_compra__c', $result['soql']);
        $this->assertStringContainsString('PRO_FEC_Fecha_compra__c', $result['soql']);
        $this->assertStringContainsString('Comprador_oportunidad__c', $result['soql']);

        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-opportunity-1',
            'record_type_name' => 'Venta',
            'owner_delegation' => 'Alcobendas',
            'delivery_store' => 'HR MOTOR ALICANTE',
            'amount' => 12500.50,
            'opo_for_importe_total' => 13000.75,
            'portal_resolved' => 'Web',
            'portal_resolution_source' => 'opportunity',
            'reservation' => true,
            'cv_signed' => false,
            'gestion_de_venta' => false,
            'vehicle_sale_price' => 12000,
            'vehicle_purchase_price' => 9000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-03 00:00:00',
            'vehicle_buyer_id' => '005-buyer-1',
            'vehicle_buyer_name' => 'Comprador Uno',
        ]);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-opportunity-2',
            'portal_resolved' => 'Meta',
            'amount' => 18000,
            'opo_for_importe_total' => 18100,
            'portal_resolution_source' => 'lead',
            'portal_resolution_lead_id' => '00Q-lead-1',
            'cv_signed' => true,
            'gestion_de_venta' => true,
        ]);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-opportunity-3',
            'portal_resolved' => 'Google Maps',
            'portal_resolution_source' => 'lead',
            'stage_name' => 'Cerrada Perdida',
        ]);
    }
}
