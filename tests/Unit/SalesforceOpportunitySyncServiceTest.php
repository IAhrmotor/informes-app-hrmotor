<?php

namespace Tests\Unit;

use App\Models\LeadRaw;
use App\Models\SalesforceOpportunity;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceLeadFieldResolver;
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
            app(SalesforceLeadFieldResolver::class),
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
                    if (! str_contains($soql, 'Fuente_origen__c')) {
                        throw new \RuntimeException('La consulta auxiliar de Leads no contiene la fuente nueva esperada.');
                    }

                    return [
                        [
                            'Id' => '00Q-lead-1',
                            'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                            'Phone' => '600 000 001',
                            'MobilePhone' => null,
                            'Email' => 'cliente@example.com',
                            'Fuente_origen__c' => 'Coches.net',
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

        $service = $this->service($client);
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
            'portal_resolved' => 'Coches.net',
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

        $storedLeadOpportunity = SalesforceOpportunity::query()->where('salesforce_id', '006-opportunity-2')->firstOrFail();
        $this->assertSame('Coches.net', data_get($storedLeadOpportunity->portal_resolution_debug, 'selectedLeadSourceNewRaw'));
        $this->assertSame('Meta', data_get($storedLeadOpportunity->portal_resolution_debug, 'selectedLeadLegacyPortalRaw'));
        $this->assertSame('Fuente_origen__c', data_get($storedLeadOpportunity->portal_resolution_debug, 'selectedLeadEffectiveSourceField'));
        $this->assertTrue(data_get($storedLeadOpportunity->portal_resolution_debug, 'selectedLeadConflict'));
    }

    public function test_fallback_leads_raw_reconstruye_fuente_nueva_desde_raw_payload_y_conserva_legacy_sin_ella(): void
    {
        LeadRaw::query()->create([
            'salesforce_id' => '00Q-raw-new',
            'lead_created_at' => '2026-05-20 10:00:00',
            'remitente_lead' => 'nuevo@example.com',
            'portal' => 'Google Maps',
            'raw_payload' => [
                'Email' => 'nuevo@example.com',
                'Fuente_origen__c' => 'Wallapop',
                'Portal_Text__c' => 'Google Maps',
            ],
        ]);
        LeadRaw::query()->create([
            'salesforce_id' => '00Q-raw-legacy',
            'lead_created_at' => '2026-05-19 10:00:00',
            'remitente_lead' => 'legacy@example.com',
            'portal' => 'Google Maps',
            'raw_payload' => [
                'Email' => 'legacy@example.com',
                'Portal_Text__c' => 'Google Maps',
            ],
        ]);

        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                $this->assertLeadQuery($soql);

                return [];
            }

            private function assertLeadQuery(string $soql): void
            {
                if (! str_contains($soql, 'FROM Lead') || ! str_contains($soql, 'Fuente_origen__c')) {
                    throw new \RuntimeException('La consulta auxiliar de Leads no contiene la fuente nueva esperada.');
                }
            }
        };
        $service = $this->service($client);
        $opportunities = [
            $this->opportunityForLeadRaw('nuevo@example.com'),
            $this->opportunityForLeadRaw('legacy@example.com'),
        ];
        $matches = $service->relatedLeadMatchesForOpportunities($opportunities);

        $newResult = $service->resolvePortalForRecord($opportunities[0], $matches);
        $legacyResult = $service->resolvePortalForRecord($opportunities[1], $matches);

        $this->assertSame('Wallapop', $newResult['portal']);
        $this->assertSame('Fuente_origen__c', data_get($newResult, 'debug.selectedLeadEffectiveSourceField'));
        $this->assertSame('Google Maps', $legacyResult['portal']);
        $this->assertSame('Portal_Text__c', data_get($legacyResult, 'debug.selectedLeadEffectiveSourceField'));
        $this->assertTrue(data_get($legacyResult, 'debug.selectedLeadUsedFallback'));
    }

    public function test_matching_de_lead_no_cambia_cuando_otro_registro_aporta_el_telefono_bruto_de_otro_candidato(): void
    {
        $target = $this->opportunityForPhone('+34 600 000 001');
        $other = $this->opportunityForPhone('600000001');
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                $records = [];

                if (str_contains($soql, "Phone LIKE '%6%0%0%0%0%0%0%0%1%'")) {
                    return [
                        $this->lead('00Q-target-lead', '+34 600 000 001', 'Coches.net', '2026-05-10T10:00:00.000+0000'),
                        $this->lead('00Q-batch-lead', '600000001', 'Wallapop', '2026-05-11T10:00:00.000+0000'),
                    ];
                }

                if (str_contains($soql, "'+34 600 000 001'")) {
                    $records[] = $this->lead('00Q-target-lead', '+34 600 000 001', 'Coches.net', '2026-05-10T10:00:00.000+0000');
                }

                if (str_contains($soql, "'600000001'")) {
                    $records[] = $this->lead('00Q-batch-lead', '600000001', 'Wallapop', '2026-05-11T10:00:00.000+0000');
                }

                return $records;
            }

            private function lead(string $id, string $phone, string $source, string $createdDate): array
            {
                return [
                    'Id' => $id,
                    'CreatedDate' => $createdDate,
                    'Phone' => $phone,
                    'MobilePhone' => null,
                    'Email' => null,
                    'Fuente_origen__c' => $source,
                    'Portal_Text__c' => null,
                    'LEA_SEL_Fuente_Origen__c' => null,
                    'Fuente_Nuevo__c' => null,
                ];
            }
        };
        $service = $this->service($client);

        $isolated = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));
        $batched = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target, $other]));

        $this->assertSame('Wallapop', $isolated['portal']);
        $this->assertSame($isolated, $batched);
        $this->assertSame('00Q-batch-lead', $batched['lead_id']);
    }

    public function test_opportunity_sin_email_ni_telefono_no_consulta_leads_y_conserva_su_fallback(): void
    {
        $client = new class extends SalesforceClient
        {
            public int $queries = 0;

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries++;

                return [];
            }
        };
        $service = $this->service($client);
        $target = $this->opportunityForPhone('', portal: 'Buscador');
        $matches = $service->relatedLeadMatchesForOpportunities([$target]);
        $result = $service->resolvePortalForRecord($target, $matches);

        $this->assertSame(0, $client->queries);
        $this->assertSame('Web', $result['portal']);
        $this->assertSame('fallback_web', $result['source']);
    }

    public function test_matching_no_importa_un_lead_recuperado_exclusivamente_por_otro_registro_del_lote(): void
    {
        $target = $this->opportunityForPhone('+34 611 000 001', portal: 'Exposicion');
        $other = $this->opportunityForPhone('611000001');
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                if (! str_contains($soql, "'611000001'")
                    && ! str_contains($soql, "Phone LIKE '%6%1%1%0%0%0%0%0%1%'")) {
                    return [];
                }

                return [[
                    'Id' => '00Q-other-only',
                    'CreatedDate' => '2026-05-11T10:00:00.000+0000',
                    'Phone' => '611000001',
                    'MobilePhone' => null,
                    'Email' => null,
                    'Fuente_origen__c' => 'Web',
                    'Portal_Text__c' => null,
                    'LEA_SEL_Fuente_Origen__c' => null,
                    'Fuente_Nuevo__c' => null,
                ]];
            }
        };
        $service = $this->service($client);

        $isolated = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));
        $batched = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target, $other]));

        $this->assertSame('Web', $isolated['portal']);
        $this->assertSame('lead', $isolated['source']);
        $this->assertSame($isolated, $batched);
    }

    public function test_fallback_local_de_email_se_decide_por_identificador_y_no_por_resultado_global_del_lote(): void
    {
        LeadRaw::query()->create([
            'salesforce_id' => '00Q-email-fallback',
            'lead_created_at' => '2026-05-10 10:00:00',
            'remitente_lead' => 'target@example.test',
            'portal' => 'Coches.net',
            'raw_payload' => [
                'Email' => 'target@example.test',
                'Portal_Text__c' => 'Coches.net',
            ],
        ]);
        $target = $this->opportunityForLeadRaw('target@example.test');
        $other = $this->opportunityForLeadRaw('other@example.test');
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                if (! str_contains($soql, "'other@example.test'")) {
                    return [];
                }

                return [[
                    'Id' => '00Q-other-email',
                    'CreatedDate' => '2026-05-11T10:00:00.000+0000',
                    'Phone' => null,
                    'MobilePhone' => null,
                    'Email' => 'other@example.test',
                    'Fuente_origen__c' => 'Wallapop',
                ]];
            }
        };
        $service = $this->service($client);

        $isolated = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));
        $batched = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target, $other]));

        $this->assertSame('Coches.net', $isolated['portal']);
        $this->assertSame('00Q-email-fallback', $isolated['lead_id']);
        $this->assertSame($isolated, $batched);
    }

    public function test_empate_temporal_de_leads_se_resuelve_por_salesforce_id(): void
    {
        $target = $this->opportunityForPhone('633 000 001');
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                return [
                    $this->lead('00Q-z', 'Wallapop'),
                    $this->lead('00Q-a', 'Coches.net'),
                ];
            }

            private function lead(string $id, string $source): array
            {
                return [
                    'Id' => $id,
                    'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                    'Phone' => '633-000-001',
                    'MobilePhone' => null,
                    'Email' => null,
                    'Fuente_origen__c' => $source,
                ];
            }
        };
        $service = $this->service($client);

        $result = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));

        $this->assertSame('Coches.net', $result['portal']);
        $this->assertSame('00Q-a', $result['lead_id']);
    }

    public function test_matching_es_invariante_en_primera_ultima_y_siguiente_posicion_de_chunk(): void
    {
        $target = $this->opportunityForPhone('+34 622 000 001');
        $other = $this->opportunityForPhone('622000001');
        $fillers = collect(range(1, 99))
            ->map(fn (int $number): array => $this->opportunityForPhone('7'.str_pad((string) $number, 8, '0', STR_PAD_LEFT)))
            ->all();
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                $records = [];

                if (str_contains($soql, "Phone LIKE '%6%2%2%0%0%0%0%0%1%'")) {
                    return [
                        $this->lead('00Q-stable-target', '+34 622 000 001', 'Coches.net'),
                        $this->lead('00Q-shared-normalized', '622000001', 'Wallapop'),
                    ];
                }

                if (str_contains($soql, "'+34 622 000 001'")) {
                    $records[] = $this->lead('00Q-stable-target', '+34 622 000 001', 'Coches.net');
                }

                if (str_contains($soql, "'622000001'")) {
                    $records[] = $this->lead('00Q-shared-normalized', '622000001', 'Wallapop');
                }

                return $records;
            }

            private function lead(string $id, string $phone, string $source): array
            {
                return [
                    'Id' => $id,
                    'CreatedDate' => $id === '00Q-shared-normalized'
                        ? '2026-05-11T10:00:00.000+0000'
                        : '2026-05-10T10:00:00.000+0000',
                    'Phone' => $phone,
                    'MobilePhone' => null,
                    'Email' => null,
                    'Fuente_origen__c' => $source,
                ];
            }
        };
        $service = $this->service($client);
        $batches = [
            [$target, ...$fillers, $other],
            [...$fillers, $target, $other],
            [...$fillers, $other, $target],
        ];
        $isolated = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));

        foreach ($batches as $batch) {
            $result = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities($batch));

            $this->assertSame($isolated, $result);
        }
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

        $service = $this->service($client);
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

        $service = $this->service($client);

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
        $service = $this->service($client);

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

        $result = $this->service($client)
            ->syncBySalesforceIds($ids);

        $this->assertSame(205, $result['requested']);
        $this->assertCount(3, $client->queries);
        $this->assertStringContainsString("006-quote\\'escaped", end($client->queries));
        $this->assertSame(205, count($result['missing']));
    }

    private function opportunityForLeadRaw(string $email): array
    {
        return [
            'Portal__c' => '3CX',
            'Fuente_de_Origen__c' => null,
            'Account' => [
                'Phone' => null,
                'PersonEmail' => $email,
                'AC_C_EMA_email__c' => null,
            ],
        ];
    }

    private function opportunityForPhone(string $phone, string $portal = '3CX'): array
    {
        return [
            'Portal__c' => $portal,
            'Fuente_de_Origen__c' => null,
            'Account' => [
                'Phone' => $phone,
                'PersonEmail' => null,
                'AC_C_EMA_email__c' => null,
            ],
        ];
    }

    private function service(SalesforceClient $client): SalesforceOpportunitySyncService
    {
        return new SalesforceOpportunitySyncService(
            $client,
            app(OpportunityPortalNormalizer::class),
            app(SalesforceLeadFieldResolver::class),
        );
    }
}
