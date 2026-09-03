<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use App\Services\Salesforce\SalesforceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReprocessOpportunityPortalsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reprocesa_oportunidades_actualiza_portal_y_soporta_limit(): void
    {
        $this->app->bind(SalesforceClient::class, fn () => new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                if (! str_contains($soql, 'FROM Lead')) {
                    return [];
                }

                if (! str_contains($soql, 'Fuente_origen__c')) {
                    throw new \RuntimeException('La consulta auxiliar de Leads no contiene la fuente nueva esperada.');
                }

                return [[
                    'Id' => '00Q-lead',
                    'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                    'Phone' => '600000001',
                    'MobilePhone' => null,
                    'Email' => 'cliente@example.com',
                    'Fuente_origen__c' => 'Coches.net',
                    'Portal_Text__c' => 'Google Maps',
                ]];
            }
        });

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-1',
            'name' => 'Oportunidad 1',
            'portal_original' => '3CX',
            'portal_resolved' => '3CX',
            'account_phone' => '600000001',
            'account_person_email' => 'cliente@example.com',
        ]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-2',
            'name' => 'Oportunidad 2',
            'portal_original' => 'COCHES.NET',
            'portal_resolved' => 'COCHES.NET',
        ]);

        Cache::forever('reservas_ventas_dashboard_cache_version', 1);

        $this->artisan('reports:reprocess-opportunity-portals', ['--limit' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-1',
            'portal_resolved' => 'Coches.net',
            'portal_resolution_source' => 'lead',
        ]);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-2',
            'portal_resolved' => 'COCHES.NET',
        ]);
        $opportunity = SalesforceOpportunity::query()->where('salesforce_id', '006-1')->firstOrFail();
        $this->assertSame('Coches.net', data_get($opportunity->portal_resolution_debug, 'selectedLeadSourceNewRaw'));
        $this->assertSame('Google Maps', data_get($opportunity->portal_resolution_debug, 'selectedLeadLegacyPortalRaw'));
        $this->assertSame('Fuente_origen__c', data_get($opportunity->portal_resolution_debug, 'selectedLeadEffectiveSourceField'));
        $this->assertTrue(data_get($opportunity->portal_resolution_debug, 'selectedLeadConflict'));
        $this->assertSame(2, Cache::get('reservas_ventas_dashboard_cache_version'));
    }
}
