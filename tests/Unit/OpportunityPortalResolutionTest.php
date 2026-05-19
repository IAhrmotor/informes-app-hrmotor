<?php

namespace Tests\Unit;

use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use App\Services\Salesforce\SalesforceClient;
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

    private function service(): SalesforceOpportunitySyncService
    {
        $client = new class extends SalesforceClient
        {
            public function __construct()
            {
            }
        };

        return new SalesforceOpportunitySyncService($client, app(OpportunityPortalNormalizer::class));
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
            'Portal_Text__c' => null,
            'LEA_SEL_Fuente_Origen__c' => null,
            'Fuente_Nuevo__c' => null,
        ], $item));
    }
}
