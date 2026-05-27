<?php

namespace Tests\Feature;

use App\Models\CampaignAttribution;
use App\Models\CampaignPlatformDailyMetric;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Campaigns\CampaignAttributionBuilderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_campanas_muestra_menu_y_endpoints(): void
    {
        $this->get('/informes/campanas')
            ->assertOk()
            ->assertSee('Campañas')
            ->assertSee('/informes/campanas/export/campaigns.csv', false);

        $this->get('/informes/leads')
            ->assertOk()
            ->assertSee('/informes/campanas', false);
    }

    public function test_atribucion_y_kpis_agregan_campaigns_sin_datos_personales(): void
    {
        CampaignPlatformDailyMetric::query()->create(array_merge($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-1',
            'campaign_name' => 'Spring Sale',
            'ad_id' => 'ad-1',
            'spend' => 200,
            'impressions' => 1000,
            'clicks' => 100,
            'platform_leads' => 8,
        ])));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-1',
            'name' => 'Lead Privado',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'persona_que_trabajo_id' => '005-real',
            'persona_que_trabajo_name' => 'Comercial Real',
            'fuente_origen' => 'Meta',
            'medio_origen' => 'Paid Social',
            'campaign_acquired' => 'Spring Sale',
            'acquired_id' => 'ad-1',
            'content_acquired' => 'creative-1',
            'vehicle_interest' => 'Vehiculo A',
            'phone' => '600 000 001',
            'email' => 'cliente@example.com',
            'is_converted' => true,
            'converted_opportunity_id' => '006-1',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-invalid',
            'name' => 'Lead Sin Campana',
            'created_date' => '2026-05-10 11:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'none',
            'acquired_id' => null,
            'content_acquired' => null,
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-1',
            'name' => 'Oportunidad 1',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'owner_delegation' => 'Alcobendas',
            'account_phone' => '+34 600 000 001',
            'account_person_email' => 'cliente@example.com',
            'portal_resolved' => 'Meta',
            'portal_resolution_source' => 'lead',
            'portal_resolution_lead_id' => '00Q-1',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            30
        );

        $this->assertSame(1, CampaignAttribution::query()->count());
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-1',
            'opportunity_id' => '006-1',
            'campaign_id' => 'camp-1',
            'campaign_name' => 'Spring Sale',
            'attribution_method' => 'campaign_id_match',
            'attribution_confidence' => 'high',
            'has_opportunity' => true,
            'has_reservation' => true,
            'has_sale' => true,
        ]);

        $query = http_build_query([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'attribution_window_days' => 30,
        ]);

        $summary = $this->getJson('/informes/campanas/data/summary?'.$query)
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 1)
            ->assertJsonPath('kpis.platform_leads', 8)
            ->assertJsonPath('kpis.opportunities', 1)
            ->assertJsonPath('kpis.reservations', 1)
            ->assertJsonPath('kpis.sales', 1)
            ->assertJsonPath('kpis.sale_amount', null)
            ->assertJsonPath('kpis.roas', null)
            ->json('kpis');
        $this->assertEquals(200.0, $summary['spend']);

        $campaign = $this->getJson('/informes/campanas/data/campaigns?'.$query)
            ->assertOk()
            ->assertJsonPath('items.0.campaign_name', 'Spring Sale')
            ->assertJsonPath('items.0.leads_salesforce', 1)
            ->json('items.0');
        $this->assertEquals(200.0, $campaign['cost_per_lead']);

        $csv = $this->get('/informes/campanas/export/campaigns.csv?'.$query)
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Spring Sale', $csv);
        $this->assertStringNotContainsString('cliente@example.com', $csv);
        $this->assertStringNotContainsString('600 000 001', $csv);
        $this->assertStringNotContainsString('Lead Privado', $csv);
    }

    public function test_ratios_devuelven_null_cuando_el_denominador_es_cero(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'account_id' => '123',
            'campaign_id' => 'camp-zero',
            'campaign_name' => 'Zero Clicks',
            'spend' => 30,
            'impressions' => 0,
            'clicks' => 0,
        ]));

        $query = http_build_query([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'attribution_window_days' => 30,
        ]);

        $this->getJson('/informes/campanas/data/campaigns?'.$query)
            ->assertOk()
            ->assertJsonPath('items.0.ctr', null)
            ->assertJsonPath('items.0.cpc', null)
            ->assertJsonPath('items.0.cost_per_lead', null);
    }

    private function metricRow(array $overrides = []): array
    {
        $attributes = array_merge([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-1',
            'campaign_name' => 'Campana',
            'spend' => 0,
            'impressions' => 0,
            'clicks' => 0,
            'raw_payload' => [],
            'synced_at' => '2026-05-10 12:00:00',
        ], $overrides);

        $attributes['unique_key'] = CampaignPlatformDailyMetric::uniqueKey($attributes);

        return $attributes;
    }
}
