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

    public function test_incremental_descubre_por_last_modified_sin_usarlo_como_fecha_funcional(): void
    {
        $service = new SalesforceOpportunitySyncService(
            new class extends SalesforceClient
            {
                public function __construct() {}
            },
            app(OpportunityPortalNormalizer::class),
        );
        $soql = $service->modifiedSoql(
            CarbonImmutable::parse('2026-08-24', 'UTC'),
            CarbonImmutable::parse('2026-08-26', 'UTC'),
        );

        $this->assertStringContainsString('LastModifiedDate >= 2026-08-24T00:00:00Z', $soql);
        $this->assertStringContainsString('LastModifiedDate < 2026-08-26T00:00:00Z', $soql);
        $this->assertStringContainsString('OPO_FEC_Fecha_de_reserva__c', $soql);
        $this->assertStringContainsString('Fecha_firma_contrato__c', $soql);
        $this->assertStringNotContainsString('CloseDate AS cancellation', $soql);
    }

    public function test_guarda_oportunidades_y_resuelve_portal_desde_salesforce_o_lead(): void
    {
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

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
                        'Comisi_n_Financiera__c' => 1234.56,
                        'OPO_DIV_Descuento_financiera__c' => 78.90,
                        'zona_financiera__c' => 'Zona Carlos',
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
                        'Captador_de_cita__c' => '005-contact-center-1',
                        'Captador_de_cita__r' => ['Name' => 'Maria Paz Vidal Perez'],
                        'Captador__c' => 'Jose Mari',
                        'Comisi_n_Captador__c' => 5.0,
                        'Fecha_captador__c' => '2026-05-01',
                        'Captador_2__c' => 'German Olsen',
                        'Captador_3__c' => null,
                        'Captador_4__c' => null,
                        'Fecha_captado_2__c' => '2026-05-02',
                        'Fecha_captador_3__c' => null,
                        'Fecha_captador_4__c' => null,
                        'OPO_CAS_Reserva__c' => true,
                        'OPO_FEC_Fecha_de_reserva__c' => '2026-05-02',
                        'OPO_CAS_Contrato_CV_firmado__c' => false,
                        'Fecha_firma_contrato__c' => null,
                        'Tienda_de_entrega__c' => 'HR MOTOR ALICANTE',
                        'Gestion_de_venta__c' => false,
                        'OPO_FEC_Fecha_entrega__c' => '2026-05-10',
                        'Delegacion_del_propietario__c' => 'CAPTADOR U ONLINE',
                        'Facturado_Facilitea__c' => false,
                        'Numero_Factura_Facilitea__c' => null,
                        'OPO_BUS_Vehiculo_a_tasar__c' => '01t-appraise-1',
                        'OPO_BUS_Vehiculo_a_tasar__r' => [
                            'Name' => '1234ABC Opel Corsa',
                        ],
                        'OPP_BUS_Vehiculo_de_interes__r' => [
                            'Name' => '5678DEF Peugeot 308',
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
                        'Captador_de_cita__c' => '005-contact-center-2',
                        'Captador_de_cita__r' => ['Name' => 'Yuleidis Garcia'],
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
        $this->assertStringContainsString('Captador_de_cita__c', $result['soql']);
        $this->assertStringContainsString('Captador_de_cita__r.Name', $result['soql']);
        $this->assertStringContainsString('Captador__c', $result['soql']);
        $this->assertStringContainsString('Comisi_n_Captador__c', $result['soql']);
        $this->assertStringContainsString('Fecha_captador__c', $result['soql']);
        $this->assertStringContainsString('Captador_2__c', $result['soql']);
        $this->assertStringContainsString('Fecha_captado_2__c', $result['soql']);
        $this->assertStringContainsString('OPO_FEC_Fecha_entrega__c', $result['soql']);
        $this->assertStringContainsString('Delegacion_del_propietario__c', $result['soql']);
        $this->assertStringContainsString('Facturado_Facilitea__c', $result['soql']);
        $this->assertStringContainsString('Numero_Factura_Facilitea__c', $result['soql']);
        $this->assertStringContainsString('OPO_BUS_Vehiculo_a_tasar__c', $result['soql']);
        $this->assertStringContainsString('OPO_BUS_Vehiculo_a_tasar__r.Name', $result['soql']);
        $this->assertStringContainsString('OPP_BUS_Vehiculo_de_interes__r.Name', $result['soql']);
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
            'financial_commission' => 1234.56,
            'financial_discount' => 78.90,
            'financial_zone' => 'Zona Carlos',
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

        $storedOpportunity = SalesforceOpportunity::query()->where('salesforce_id', '006-opportunity-1')->firstOrFail();

        $this->assertSame('Jose Mari', $storedOpportunity->raw_payload['Captador__c'] ?? null);
        $this->assertSame('005-contact-center-1', $storedOpportunity->raw_payload['Captador_de_cita__c'] ?? null);
        $this->assertSame('Maria Paz Vidal Perez', data_get($storedOpportunity->raw_payload, 'Captador_de_cita__r.Name'));
        $this->assertEquals(5.0, $storedOpportunity->raw_payload['Comisi_n_Captador__c'] ?? null);
        $this->assertSame('German Olsen', $storedOpportunity->raw_payload['Captador_2__c'] ?? null);
        $this->assertSame('2026-05-10', $storedOpportunity->raw_payload['OPO_FEC_Fecha_entrega__c'] ?? null);
        $this->assertSame('1234ABC Opel Corsa', data_get($storedOpportunity->raw_payload, 'OPO_BUS_Vehiculo_a_tasar__r.Name'));
        $this->assertSame('5678DEF Peugeot 308', data_get($storedOpportunity->raw_payload, 'OPP_BUS_Vehiculo_de_interes__r.Name'));
    }

    public function test_reintenta_sin_email_de_empresa_cuando_salesforce_rechaza_el_campo_opcional(): void
    {
        $client = new class extends SalesforceClient
        {
            public int $queries = 0;

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries++;

                if (str_contains($soql, 'Account.AC_C_EMA_email__c')) {
                    throw new \RuntimeException('Salesforce SOQL: error remoto HTTP 400.');
                }

                return [];
            }
        };

        $service = new SalesforceOpportunitySyncService($client, app(OpportunityPortalNormalizer::class));
        $result = $service->sync(
            CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-07-02 00:00:00', 'UTC'),
        );

        $this->assertSame(2, $client->queries);
        $this->assertSame(0, $result['saved']);
        $this->assertStringNotContainsString('Account.AC_C_EMA_email__c', $result['soql']);
    }

    public function test_no_reintenta_errores_remotos_que_no_sean_un_rechazo_http_400(): void
    {
        $client = new class extends SalesforceClient
        {
            public int $queries = 0;

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries++;

                throw new \RuntimeException('Salesforce SOQL: error remoto HTTP 503.');
            }
        };

        $service = new SalesforceOpportunitySyncService($client, app(OpportunityPortalNormalizer::class));

        try {
            $service->sync(
                CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
                CarbonImmutable::parse('2026-07-02 00:00:00', 'UTC'),
            );
            $this->fail('Se esperaba propagar el error remoto.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Salesforce SOQL: error remoto HTTP 503.', $exception->getMessage());
        }

        $this->assertSame(1, $client->queries);
    }

    public function test_sincroniza_por_ids_con_query_acotada_y_mapeo_canonico_idempotente(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [[
                    'Id' => '006-by-id',
                    'Name' => 'Recuperada por ID',
                    'CreatedDate' => '2026-03-01T10:00:00.000+0000',
                    'LastModifiedDate' => '2026-08-20T10:00:00.000+0000',
                    'StageName' => 'Cerrada Perdida',
                    'RecordType' => ['Name' => 'Venta'],
                    'OwnerId' => '005-owner',
                    'Owner' => ['Name' => 'Comercial', 'IsActive' => true, 'USR_SEL_Delegacion__c' => 'Alicante'],
                    'OPO_CAS_Reserva__c' => true,
                    'OPO_FEC_Fecha_de_reserva__c' => '2026-03-10',
                    'OPO_CAS_Contrato_CV_firmado__c' => false,
                ]];
            }
        };
        $service = new SalesforceOpportunitySyncService($client, app(OpportunityPortalNormalizer::class));

        $first = $service->syncBySalesforceIds(['006-by-id', '006-by-id']);
        $second = $service->syncBySalesforceIds(collect(['006-by-id']));

        $this->assertSame(1, $first['requested']);
        $this->assertSame(1, $first['saved']);
        $this->assertSame(1, $second['saved']);
        $this->assertDatabaseCount('salesforce_opportunities', 1);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-by-id',
            'reservation' => true,
            'reservation_date' => '2026-03-10 00:00:00',
            'owner_id' => '005-owner',
        ]);
        $this->assertStringContainsString("Id IN ('006-by-id')", $first['soql']);
        $this->assertStringNotContainsString('CreatedDate >=', $first['soql']);
    }

    public function test_sincronizacion_por_ids_chunking_y_escapado(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [];
            }
        };
        $ids = collect(range(1, 204))->map(fn (int $number): string => sprintf('006-%03d', $number));
        $ids->push("006-quote'escaped");

        $result = (new SalesforceOpportunitySyncService($client, app(OpportunityPortalNormalizer::class)))
            ->syncBySalesforceIds($ids);

        $this->assertSame(205, $result['requested']);
        $this->assertCount(3, $client->queries);
        $this->assertStringContainsString("006-quote\\'escaped", end($client->queries));
        $this->assertSame(205, count($result['missing']));
    }
}
