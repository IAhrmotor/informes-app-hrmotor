<?php

namespace Tests\Unit;

use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceLeadFieldResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OpportunityPortalResolutionTest extends TestCase
{
    public function test_portal_claro_usa_opportunity(): void
    {
        $result = $this->service()->resolvePortalForRecord($this->opportunity(['Portal__c' => 'COCHES.NET']), collect());

        $this->assertSame('Coches.net', $result['portal']);
        $this->assertSame('opportunity', $result['source']);
    }

    public function test_exposicion_y_vacio_buscan_lead_alternativo(): void
    {
        foreach (['Exposición', null] as $portal) {
            $result = $this->service()->resolvePortalForRecord(
                $this->opportunity(['Portal__c' => $portal]),
                $this->leads([
                    ['Id' => 'lead-bad', 'Portal_Text__c' => 'Exposición', 'CreatedDate' => '2026-05-10T10:00:00.000+0000'],
                    ['Id' => 'lead-good', 'Portal_Text__c' => 'Google Maps', 'CreatedDate' => '2026-05-09T10:00:00.000+0000'],
                ])
            );

            $this->assertSame('Google Maps', $result['portal']);
            $this->assertSame('lead', $result['source']);
            $this->assertSame('lead-good', $result['lead_id']);
        }
    }

    public function test_3cx_y_llamada_directa_sin_alternativa_quedan_sin_clasificar(): void
    {
        foreach (['3CX', 'Llamada directa'] as $portal) {
            $result = $this->service()->resolvePortalForRecord($this->opportunity(['Portal__c' => $portal]), collect());

            $this->assertSame('Sin clasificar', $result['portal']);
            $this->assertSame('unclassified', $result['source']);
        }
    }

    public function test_buscador_sin_alternativa_queda_web(): void
    {
        $result = $this->service()->resolvePortalForRecord($this->opportunity(['Portal__c' => 'Buscador']), collect());

        $this->assertSame('Web', $result['portal']);
        $this->assertSame('fallback_web', $result['source']);
    }

    public function test_si_no_hay_lead_valido_usa_fuente_de_origen(): void
    {
        $result = $this->service()->resolvePortalForRecord(
            $this->opportunity(['Portal__c' => '3CX', 'Fuente_de_Origen__c' => 'COCHES.NET']),
            $this->leads([
                ['Id' => 'lead-bad', 'Portal_Text__c' => 'Llamada directa', 'CreatedDate' => '2026-05-10T10:00:00.000+0000'],
            ])
        );

        $this->assertSame('Coches.net', $result['portal']);
        $this->assertSame('opportunity_source', $result['source']);
    }

    public function test_exposicion_queda_solo_si_no_hay_alternativa(): void
    {
        $result = $this->service()->resolvePortalForRecord($this->opportunity(['Portal__c' => 'Exposición']), collect());

        $this->assertSame('Exposición', $result['portal']);
        $this->assertSame('fallback_exposicion', $result['source']);
    }

    public function test_lead_relacionado_prioriza_portal_text_sobre_fuentes_legacy_actuales(): void
    {
        $result = $this->service()->resolvePortalForRecord(
            $this->opportunity(['Portal__c' => '3CX']),
            $this->leads([[
                'Id' => 'lead-conflict',
                'Portal_Text__c' => 'Google Maps',
                'LEA_SEL_Fuente_Origen__c' => 'Coches.net',
                'Fuente_Nuevo__c' => 'Wallapop',
                'CreatedDate' => '2026-05-10T10:00:00.000+0000',
            ]]),
        );

        $this->assertSame('Google Maps', $result['portal']);
        $this->assertSame('lead', $result['source']);
        $this->assertSame('lead-conflict', $result['lead_id']);
        $this->assertSame('Portal_Text__c', data_get($result, 'debug.selectedLeadLegacySourceField'));
        $this->assertSame('Portal_Text__c', data_get($result, 'debug.selectedLeadEffectiveSourceField'));
        $this->assertTrue(data_get($result, 'debug.selectedLeadUsedFallback'));
    }

    public function test_fallback_legacy_conserva_prioridad_de_los_tres_campos(): void
    {
        foreach ([
            [['Portal_Text__c' => 'Google Maps', 'LEA_SEL_Fuente_Origen__c' => 'Coches.net', 'Fuente_Nuevo__c' => 'Wallapop'], 'Google Maps', 'Portal_Text__c'],
            [['Portal_Text__c' => null, 'LEA_SEL_Fuente_Origen__c' => 'Coches.net', 'Fuente_Nuevo__c' => 'Wallapop'], 'Coches.net', 'LEA_SEL_Fuente_Origen__c'],
            [['Portal_Text__c' => null, 'LEA_SEL_Fuente_Origen__c' => null, 'Fuente_Nuevo__c' => 'Wallapop'], 'Wallapop', 'Fuente_Nuevo__c'],
        ] as [$leadFields, $expectedPortal, $expectedField]) {
            $result = $this->service()->resolvePortalForRecord(
                $this->opportunity(['Portal__c' => '3CX']),
                $this->leads([[...$leadFields, 'Id' => 'lead-legacy-priority']]),
            );

            $this->assertSame($expectedPortal, $result['portal']);
            $this->assertSame($expectedField, data_get($result, 'debug.selectedLeadLegacySourceField'));
            $this->assertSame($expectedField, data_get($result, 'debug.selectedLeadEffectiveSourceField'));
            $this->assertTrue(data_get($result, 'debug.selectedLeadUsedFallback'));
        }
    }

    public function test_fuente_nueva_del_lead_gana_y_deja_traza_del_conflicto_legacy(): void
    {
        $result = $this->service()->resolvePortalForRecord(
            $this->opportunity(['Portal__c' => '3CX']),
            $this->leads([[
                'Id' => 'lead-new-source',
                'Fuente_origen__c' => 'Coches.net',
                'Portal_Text__c' => 'Google Maps',
                'LEA_SEL_Fuente_Origen__c' => 'Wallapop',
                'CreatedDate' => '2026-05-10T10:00:00.000+0000',
            ]]),
        );

        $this->assertSame('Coches.net', $result['portal']);
        $this->assertSame('lead', $result['source']);
        $this->assertSame('Coches.net', data_get($result, 'debug.selectedLeadSourceNewRaw'));
        $this->assertSame('Google Maps', data_get($result, 'debug.selectedLeadLegacyPortalRaw'));
        $this->assertSame('Portal_Text__c', data_get($result, 'debug.selectedLeadLegacySourceField'));
        $this->assertSame('Fuente_origen__c', data_get($result, 'debug.selectedLeadEffectiveSourceField'));
        $this->assertFalse(data_get($result, 'debug.selectedLeadUsedFallback'));
        $this->assertTrue(data_get($result, 'debug.selectedLeadConflict'));
    }

    public function test_fuente_nueva_vacia_o_whitespace_usa_fallback_legacy(): void
    {
        foreach (['', '   '] as $newSource) {
            $result = $this->service()->resolvePortalForRecord(
                $this->opportunity(['Portal__c' => '3CX']),
                $this->leads([[
                    'Id' => 'lead-legacy-source',
                    'Fuente_origen__c' => $newSource,
                    'Portal_Text__c' => 'Google Maps',
                    'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                ]]),
            );

            $this->assertSame('Google Maps', $result['portal']);
            $this->assertSame('Portal_Text__c', data_get($result, 'debug.selectedLeadEffectiveSourceField'));
            $this->assertTrue(data_get($result, 'debug.selectedLeadUsedFallback'));
        }
    }

    public function test_lead_con_solo_fuente_nueva_puede_resolver_procedencia(): void
    {
        $result = $this->service()->resolvePortalForRecord(
            $this->opportunity(['Portal__c' => '3CX']),
            $this->leads([[
                'Id' => 'lead-only-new-source',
                'Fuente_origen__c' => 'Wallapop',
                'CreatedDate' => '2026-05-10T10:00:00.000+0000',
            ]]),
        );

        $this->assertSame('Wallapop', $result['portal']);
        $this->assertSame('lead', $result['source']);
        $this->assertSame('lead-only-new-source', $result['lead_id']);
    }

    public function test_placeholder_nuevo_no_recurre_a_legacy_ni_a_fuente_de_opportunity(): void
    {
        $result = $this->service()->resolvePortalForRecord(
            $this->opportunity(['Portal__c' => '3CX', 'Fuente_de_Origen__c' => 'Coches.net']),
            $this->leads([[
                'Id' => 'lead-placeholder',
                'Fuente_origen__c' => 'No identificado',
                'Portal_Text__c' => 'Google Maps',
                'CreatedDate' => '2026-05-10T10:00:00.000+0000',
            ]]),
        );

        $this->assertSame('Sin clasificar', $result['portal']);
        $this->assertSame('lead', $result['source']);
        $this->assertSame('lead-placeholder', $result['lead_id']);
        $this->assertSame('No identificado', data_get($result, 'debug.selectedLeadPortalRaw'));
        $this->assertSame('Fuente_origen__c', data_get($result, 'debug.selectedLeadEffectiveSourceField'));
        $this->assertFalse(data_get($result, 'debug.selectedLeadUsedFallback'));
    }

    public function test_portal_concluyente_de_opportunity_sigue_ganando_a_fuente_nueva_del_lead(): void
    {
        $result = $this->service()->resolvePortalForRecord(
            $this->opportunity(['Portal__c' => 'Coches.net']),
            $this->leads([[
                'Id' => 'lead-new-source',
                'Fuente_origen__c' => 'Wallapop',
                'CreatedDate' => '2026-05-10T10:00:00.000+0000',
            ]]),
        );

        $this->assertSame('Coches.net', $result['portal']);
        $this->assertSame('opportunity', $result['source']);
        $this->assertNull($result['lead_id']);
    }

    private function service(): SalesforceOpportunitySyncService
    {
        $client = new class extends SalesforceClient
        {
            public function __construct() {}
        };

        return new SalesforceOpportunitySyncService(
            $client,
            app(OpportunityPortalNormalizer::class),
            app(SalesforceLeadFieldResolver::class),
        );
    }

    private function opportunity(array $overrides = []): array
    {
        return array_replace_recursive([
            'Portal__c' => null,
            'Fuente_de_Origen__c' => null,
            'Account' => [
                'Phone' => '600000001',
                'PersonEmail' => 'cliente@example.com',
                'AC_C_EMA_email__c' => null,
            ],
        ], $overrides);
    }

    private function leads(array $items): Collection
    {
        return collect($items)->map(fn (array $item) => array_replace([
            'Phone' => '600000001',
            'MobilePhone' => null,
            'Email' => 'cliente@example.com',
            'Fuente_origen__c' => null,
            'Portal_Text__c' => null,
            'LEA_SEL_Fuente_Origen__c' => null,
            'Fuente_Nuevo__c' => null,
        ], $item));
    }
}
