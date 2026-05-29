<?php

namespace Tests\Feature;

use App\Models\CampaignAttribution;
use App\Models\CampaignPlatformDailyMetric;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Campaigns\CampaignAttributionBuilderService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'attribution_method' => 'ad_id_match',
            'attribution_confidence' => 'high',
            'match_status' => 'Cruzada por ID',
            'campaign_source_type' => 'platform_campaign',
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
            ->assertJsonStructure(['diagnostics' => [
                'platform_campaigns',
                'salesforce_origins',
                'crossed_campaigns',
                'salesforce_only_by_campaign',
                'salesforce_only_by_origin',
            ]])
            ->json('kpis');
        $this->assertEquals(200.0, $summary['spend']);

        $campaign = $this->getJson('/informes/campanas/data/campaigns?'.$query)
            ->assertOk()
            ->assertJsonPath('items.0.campaign_name', 'Spring Sale')
            ->assertJsonPath('items.0.match_status', 'Cruzada por ID')
            ->assertJsonPath('items.0.campaign_source_type', 'platform_campaign')
            ->assertJsonPath('items.0.campaign_source_type_label', 'Campana plataforma')
            ->assertJsonPath('items.0.leads_salesforce', 1)
            ->json('items.0');
        $this->assertEquals(200.0, $campaign['cost_per_lead']);

        $csv = $this->get('/informes/campanas/export/campaigns.csv?'.$query)
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Tipo', $csv);
        $this->assertStringContainsString('Estado de cruce', $csv);
        $this->assertStringContainsString('Campana plataforma', $csv);
        $this->assertStringContainsString('Spring Sale', $csv);
        $this->assertStringNotContainsString('cliente@example.com', $csv);
        $this->assertStringNotContainsString('600 000 001', $csv);
        $this->assertStringNotContainsString('Lead Privado', $csv);
    }

    public function test_salesforce_campaign_without_platform_creates_salesforce_only_attribution(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-sf-only',
            'name' => 'Lead Salesforce',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Google',
            'medio_origen' => 'cpc',
            'campaign_acquired' => 'Campana solo Salesforce',
            'portal_text' => 'Google',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            30
        );

        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-sf-only',
            'platform' => 'salesforce',
            'campaign_name' => 'Campana solo Salesforce',
            'attribution_method' => 'salesforce_only',
            'match_status' => 'Sin inversion asociada',
            'campaign_source_type' => 'salesforce_campaign_without_spend',
        ]);

        $query = http_build_query([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'attribution_window_days' => 30,
        ]);

        $this->getJson('/informes/campanas/data/campaigns?'.$query)
            ->assertOk()
            ->assertJsonPath('items.0.classification', 'Revisar inversion/tracking')
            ->assertJsonPath('items.0.match_status', 'Sin inversion asociada')
            ->assertJsonPath('items.0.campaign_source_type', 'salesforce_campaign_without_spend');
    }

    public function test_platform_spend_without_salesforce_leads_is_review_tracking(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'account_id' => '123',
            'campaign_id' => 'camp-tracking',
            'campaign_name' => 'Tracking roto',
            'spend' => 900,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        $query = http_build_query([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'attribution_window_days' => 30,
        ]);

        $this->getJson('/informes/campanas/data/campaigns?'.$query)
            ->assertOk()
            ->assertJsonPath('items.0.classification', 'Revisar tracking')
            ->assertJsonPath('items.0.match_status', 'Sin leads Salesforce');
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

    public function test_salesforce_source_medium_only_is_presented_as_origin_and_never_stop(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-origin',
            'name' => 'Lead Origen',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Google Maps',
            'medio_origen' => 'Llamada',
            'portal_text' => 'Google Maps',
            'medio_nuevo' => 'Llamada',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            30
        );

        $query = http_build_query([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'attribution_window_days' => 30,
        ]);

        $this->getJson('/informes/campanas/data/campaigns?'.$query)
            ->assertOk()
            ->assertJsonPath('items.0.campaign_source_type', 'salesforce_origin')
            ->assertJsonPath('items.0.campaign_source_type_label', 'Procedencia Salesforce')
            ->assertJsonPath('items.0.display_campaign', 'Google Maps · Llamada')
            ->assertJsonPath('items.0.match_status', 'Procedencia Salesforce')
            ->assertJsonPath('items.0.classification', 'Procedencia Salesforce');
    }

    public function test_sale_amount_column_when_available_calculates_roas_and_roi(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->decimal('sale_amount', 14, 2)->nullable();
        });

        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-amount',
            'campaign_name' => 'Campaign Amount',
            'ad_id' => 'ad-amount',
            'spend' => 500,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-amount',
            'name' => 'Lead Amount',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campaign Amount',
            'acquired_id' => 'ad-amount',
            'converted_opportunity_id' => '006-amount',
            'phone' => '600 000 002',
            'email' => 'amount@example.com',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-amount',
            'name' => 'Oportunidad Amount',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'account_phone' => '+34 600 000 002',
            'account_person_email' => 'amount@example.com',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
        ]);

        DB::table('salesforce_opportunities')
            ->where('salesforce_id', '006-amount')
            ->update(['sale_amount' => 15000]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            30
        );

        $query = http_build_query([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'attribution_window_days' => 30,
        ]);

        $this->getJson('/informes/campanas/data/summary?'.$query)
            ->assertOk()
            ->assertJsonPath('kpis.sale_amount', 15000)
            ->assertJsonPath('kpis.roas', 30)
            ->assertJsonPath('kpis.estimated_roi', 29);
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
