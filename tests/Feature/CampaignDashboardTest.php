<?php

namespace Tests\Feature;

use App\Models\CampaignAttribution;
use App\Models\CampaignPlatformDailyMetric;
use App\Models\ReportUser;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Campaigns\CampaignAttributionBuilderService;
use App\Services\Campaigns\CampaignSaleAmountResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CampaignDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_dashboard_campanas_muestra_menu_y_endpoints(): void
    {
        $this->get('/informes/campanas')
            ->assertOk()
            ->assertSee('/informes/campanas/export/campaigns.csv', false)
            ->assertSee('campaignCharts', false);

        $this->get('/informes/leads')
            ->assertOk()
            ->assertSee('/informes/campanas', false);
    }

    public function test_ui_drawer_and_columns_follow_platform_campaign_v1_contract(): void
    {
        $html = $this->get('/informes/campanas')->assertOk()->getContent();

        $this->assertStringContainsString('id="campaignCharts"', $html);
        $this->assertStringContainsString('id="platformComparison"', $html);
        $this->assertStringContainsString('id="reviewCampaigns"', $html);
        $this->assertStringContainsString('option value="active" selected', $html);
        $this->assertStringContainsString('id="rankingsToggle"', $html);
        $this->assertStringContainsString('id="rankingsPopover"', $html);
        $this->assertStringContainsString('campaigns.dailyChart.visibleSeries', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('campaigns.dailyChart.chartType', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('data-series', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('data-chart-type', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('function lineX(index, total)', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('line-label-layer', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString("{ value: 'current_year', label: 'Ano actual' }", file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('Evolucion de tasaciones y compras', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('Campanas a revisar', $html);
        $this->assertStringNotContainsString('#7d494e', file_get_contents(resource_path('css/reports/leads-dashboard.css')));
        $this->assertStringContainsString('id="mediumAcquired"', $html);
        $this->assertStringContainsString('id="campaignAcquired"', $html);
        $this->assertStringContainsString('id="campaignId"', $html);
        $this->assertStringContainsString('id="campaignName"', $html);
        $this->assertStringContainsString('id="hasOpportunity"', $html);
        $this->assertStringContainsString('id="hasReservation"', $html);
        $this->assertStringContainsString('id="hasSale"', $html);
        $this->assertStringContainsString('id="classification"', $html);

        $this->assertStringNotContainsString('id="sourceAcquired"', $html);
        $this->assertStringNotContainsString('id="commercialUser"', $html);
        $this->assertStringNotContainsString('id="vehicleInterest"', $html);
        $this->assertStringNotContainsString('id="delegation"', $html);
        $this->assertStringNotContainsString('id="zone"', $html);
        $this->assertStringNotContainsString('id="leadStatus"', $html);
        $this->assertStringNotContainsString('data-column="campaign_source_type_label"', $html);
        $this->assertStringNotContainsString('data-column="match_status"', $html);
        $this->assertStringNotContainsString('data-column="platform_leads"', $html);
    }

    public function test_admin_ve_diagnostico_y_puede_exportar_pero_viewer_no(): void
    {
        config()->set('services.informes_auth.enabled', true);

        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'camp-export',
            'campaign_name' => 'Export Campaign',
            'spend' => 100,
        ]));

        $adminSession = [
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => 'admin@hrmotor.com',
        ];
        $viewerSession = [
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_VIEWER,
            'report_user_email' => 'viewer@hrmotor.com',
        ];

        $this->withSession($adminSession)
            ->get('/informes/campanas')
            ->assertOk()
            ->assertSee('campaignDiagnosticsPanel', false)
            ->assertSee('Export CSV');

        $this->withSession($adminSession)
            ->getJson('/informes/campanas/data/summary?'.$this->query())
            ->assertOk()
            ->assertJsonStructure(['diagnostics']);

        $this->withSession($adminSession)
            ->get('/informes/campanas/export/campaigns.csv?'.$this->query())
            ->assertOk();

        $this->withSession($viewerSession)
            ->get('/informes/campanas')
            ->assertOk()
            ->assertDontSee('campaignDiagnosticsPanel', false)
            ->assertDontSee('Export CSV');

        $this->withSession($viewerSession)
            ->getJson('/informes/campanas/data/summary?'.$this->query())
            ->assertOk()
            ->assertJsonMissingPath('diagnostics')
            ->assertJsonPath('warnings', []);

        $this->withSession($viewerSession)
            ->get('/informes/campanas/export/campaigns.csv?'.$this->query())
            ->assertForbidden();
    }

    public function test_atribucion_y_kpis_agregan_campaigns_sin_datos_personales(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
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
        ]));

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
            'amount' => 12000,
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
            'sale_amount' => 12000,
        ]);

        $query = $this->query();

        $summary = $this->getJson('/informes/campanas/data/summary?'.$query)
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 1)
            ->assertJsonPath('kpis.platform_leads', 8)
            ->assertJsonPath('kpis.opportunities', 1)
            ->assertJsonPath('kpis.reservations', 1)
            ->assertJsonPath('kpis.sales', 1)
            ->assertJsonPath('kpis.sale_amount', 12000)
            ->assertJsonPath('kpis.roas', 60)
            ->assertJsonPath('kpis.estimated_roi', 59)
            ->assertJsonStructure([
                'charts' => ['daily_evolution', 'funnel', 'platforms'],
                'daily_investment_leads',
                'daily_results',
                'platform_comparison',
                'review_campaigns',
                'diagnostics' => [
                    'platform_campaigns',
                    'salesforce_origins',
                    'crossed_campaigns',
                    'salesforce_only_by_campaign',
                    'salesforce_only_by_origin',
                ],
            ])
            ->json('kpis');
        $this->assertEquals(200.0, $summary['spend']);

        $campaign = $this->getJson('/informes/campanas/data/campaigns?'.$query)
            ->assertOk()
            ->assertJsonPath('items.0.campaign_name', 'Spring Sale')
            ->assertJsonPath('items.0.match_status', 'Cruzada por ID')
            ->assertJsonPath('items.0.campaign_source_type', 'platform_campaign')
            ->assertJsonPath('items.0.leads_salesforce', 1)
            ->json('items.0');
        $this->assertEquals(200.0, $campaign['cost_per_lead']);

        $csv = $this->get('/informes/campanas/export/campaigns.csv?'.$query)
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Spring Sale', $csv);
        $this->assertStringContainsString('ID adquirido', $csv);
        $this->assertStringNotContainsString('Tipo', $csv);
        $this->assertStringNotContainsString('Estado de cruce', $csv);
        $this->assertStringNotContainsString('Campana plataforma', $csv);
        $this->assertStringNotContainsString('cliente@example.com', $csv);
        $this->assertStringNotContainsString('600 000 001', $csv);
        $this->assertStringNotContainsString('Lead Privado', $csv);
    }

    public function test_salesforce_campaign_without_platform_creates_internal_attribution_but_not_main_campaign_row(): void
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

        $this->getJson('/informes/campanas/data/campaigns?'.$this->query())
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('items', []);

        $this->getJson('/informes/campanas/data/summary?'.$this->query())
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 0)
            ->assertJsonPath('diagnostics.salesforce_only_by_campaign', 1);
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

        $this->getJson('/informes/campanas/data/campaigns?'.$this->query())
            ->assertOk()
            ->assertJsonPath('items.0.classification', 'Revisar tracking')
            ->assertJsonPath('items.0.match_status', 'Sin leads Salesforce')
            ->assertJsonPath('items.0.campaign_source_type', 'platform_campaign');
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

        $this->getJson('/informes/campanas/data/campaigns?'.$this->query())
            ->assertOk()
            ->assertJsonPath('items.0.ctr', null)
            ->assertJsonPath('items.0.cpc', null)
            ->assertJsonPath('items.0.cost_per_lead', null);
    }

    public function test_venta_posterior_cuenta_si_el_lead_nacio_en_el_periodo_y_esta_en_ventana(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'camp-lead-pivot',
            'campaign_name' => 'Lead Pivot',
            'spend' => 100,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-lead-pivot',
            'name' => 'Lead Pivot',
            'created_date' => '2026-05-20 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Meta',
            'medio_origen' => 'Paid Social',
            'campaign_acquired' => 'Lead Pivot',
            'acquired_id' => 'camp-lead-pivot',
            'content_acquired' => null,
            'phone' => '600000001',
            'email' => 'leadpivot@example.com',
            'is_converted' => true,
            'converted_opportunity_id' => '006-lead-pivot',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-lead-pivot',
            'name' => 'Oportunidad Lead Pivot',
            'created_date' => '2026-06-01 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'owner_delegation' => 'Alcobendas',
            'account_phone' => '+34 600000001',
            'account_person_email' => 'leadpivot@example.com',
            'portal_resolved' => 'Meta',
            'portal_resolution_source' => 'lead',
            'portal_resolution_lead_id' => '00Q-lead-pivot',
            'reservation' => true,
            'reservation_date' => '2026-06-02',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-05',
            'amount' => 0,
            'opo_for_importe_total' => 12000,
        ]);

        CampaignAttribution::query()->create([
            'lead_id' => '00Q-lead-pivot',
            'opportunity_id' => '006-lead-pivot',
            'platform' => 'meta',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-lead-pivot',
            'campaign_name' => 'Lead Pivot',
            'campaign_name_key' => 'leadpivot',
            'lead_created_at' => '2026-05-20 10:00:00',
            'opportunity_created_at' => '2026-06-01 10:00:00',
            'reservation_date' => '2026-06-02',
            'sale_date' => '2026-06-05',
            'sale_amount' => 12000,
            'has_opportunity' => true,
            'has_reservation' => true,
            'has_sale' => true,
            'attribution_method' => 'campaign_id_match',
            'attribution_confidence' => 'high',
            'match_status' => 'Cruzada por ID',
            'campaign_source_type' => 'platform_campaign',
            'attribution_window_days' => 30,
        ]);

        $this->getJson('/informes/campanas/data/summary?'.$this->query())
            ->assertOk()
            ->assertJsonPath('period_mode', 'lead_pivot')
            ->assertJsonPath('kpis.leads_salesforce', 1)
            ->assertJsonPath('kpis.opportunities', 1)
            ->assertJsonPath('kpis.reservations', 1)
            ->assertJsonPath('kpis.sales', 1)
            ->assertJsonPath('kpis.sale_amount', 12000);
    }

    public function test_venta_del_periodo_no_cuenta_si_el_lead_nacio_fuera_del_periodo(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'camp-old-lead',
            'campaign_name' => 'Old Lead',
            'spend' => 100,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        CampaignAttribution::query()->create([
            'lead_id' => '00Q-old-lead',
            'opportunity_id' => '006-old-lead',
            'platform' => 'meta',
            'campaign_id' => 'camp-old-lead',
            'campaign_name' => 'Old Lead',
            'campaign_name_key' => 'oldlead',
            'lead_created_at' => '2026-04-20 10:00:00',
            'opportunity_created_at' => '2026-05-01 10:00:00',
            'reservation_date' => '2026-05-02',
            'sale_date' => '2026-05-05',
            'sale_amount' => 12000,
            'has_opportunity' => true,
            'has_reservation' => true,
            'has_sale' => true,
            'attribution_method' => 'campaign_id_match',
            'attribution_confidence' => 'high',
            'match_status' => 'Cruzada por ID',
            'campaign_source_type' => 'platform_campaign',
            'attribution_window_days' => 30,
        ]);

        $this->getJson('/informes/campanas/data/summary?'.$this->query())
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 0)
            ->assertJsonPath('kpis.opportunities', 0)
            ->assertJsonPath('kpis.reservations', 0)
            ->assertJsonPath('kpis.sales', 0)
            ->assertJsonPath('kpis.sale_amount', null);
    }

    public function test_salesforce_source_medium_only_stays_in_diagnostics_and_not_main_campaigns(): void
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

        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-origin',
            'campaign_source_type' => 'salesforce_origin',
            'match_status' => 'Procedencia Salesforce',
        ]);

        $this->getJson('/informes/campanas/data/campaigns?'.$this->query())
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('items', []);

        $this->getJson('/informes/campanas/data/summary?'.$this->query())
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 0)
            ->assertJsonPath('diagnostics.salesforce_origins', 1)
            ->assertJsonPath('diagnostics.salesforce_only_by_origin', 1);
    }

    public function test_rankings_and_charts_exclude_salesforce_origins(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'camp-platform',
            'campaign_name' => 'Meta Real',
            'spend' => 350,
            'impressions' => 900,
            'clicks' => 90,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-origin',
            'name' => 'Lead Chatbot',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Chatbot',
            'medio_origen' => 'CPC',
            'portal_text' => 'Chatbot',
            'medio_nuevo' => 'CPC',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            30
        );

        $rankings = $this->getJson('/informes/campanas/data/rankings?'.$this->query())
            ->assertOk()
            ->json('rankings');
        $rankingJson = json_encode($rankings);

        $this->assertStringContainsString('Meta Real', $rankingJson);
        $this->assertStringNotContainsString('Chatbot', $rankingJson);
        $this->assertArrayNotHasKey('salesforce_origin', $rankings);
        $this->assertArrayNotHasKey('review_investment_tracking', $rankings);

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->query())
            ->assertOk()
            ->json();

        $this->assertSame(900, $summary['charts']['funnel'][0]['value']);
        $this->assertSame(90, $summary['charts']['funnel'][1]['value']);
        $this->assertSame(1, $summary['diagnostics']['salesforce_origins']);
    }

    public function test_google_maps_chatbot_and_exposicion_do_not_appear_as_main_campaigns(): void
    {
        foreach ([
            ['00Q-maps', 'Google Maps', 'Llamada'],
            ['00Q-chatbot', 'Chatbot', 'CPC'],
            ['00Q-expo', 'Exposicion', ''],
        ] as [$id, $source, $medium]) {
            SalesforceLead::query()->create([
                'salesforce_id' => $id,
                'name' => 'Lead '.$source,
                'created_date' => '2026-05-10 10:00:00',
                'status' => 'Potencial',
                'record_type_name' => 'Venta',
                'owner_id' => '005-real',
                'owner_name' => 'Comercial Real',
                'fuente_origen' => $source,
                'medio_origen' => $medium,
                'portal_text' => $source,
                'medio_nuevo' => $medium,
                'delegacion_encargada_text' => 'Alcobendas',
            ]);
        }

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            30
        );

        $payload = $this->getJson('/informes/campanas/data/campaigns?'.$this->query())
            ->assertOk()
            ->json();

        $this->assertSame(0, $payload['total']);
        $this->assertSame([], $payload['items']);
    }

    public function test_sale_amount_column_when_available_calculates_roas_and_roi(): void
    {
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
            'amount' => 15000,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            30
        );

        $this->getJson('/informes/campanas/data/summary?'.$this->query())
            ->assertOk()
            ->assertJsonPath('kpis.sale_amount', 15000)
            ->assertJsonPath('kpis.roas', 30)
            ->assertJsonPath('kpis.estimated_roi', 29);
    }

    public function test_opo_for_importe_total_tiene_prioridad_sobre_amount(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-priority',
            'campaign_name' => 'Campaign Priority',
            'ad_id' => 'ad-priority',
            'spend' => 1000,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-priority',
            'name' => 'Lead Priority',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campaign Priority',
            'acquired_id' => 'ad-priority',
            'converted_opportunity_id' => '006-priority',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-priority',
            'name' => 'Oportunidad Priority',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'amount' => 100,
            'opo_for_importe_total' => 25000,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            30
        );

        $this->getJson('/informes/campanas/data/summary?'.$this->query())
            ->assertOk()
            ->assertJsonPath('kpis.sale_amount', 25000)
            ->assertJsonPath('kpis.roas', 25)
            ->assertJsonPath('kpis.estimated_roi', 24)
            ->assertJsonPath('diagnostics.sale_amount_field_used', 'opo_for_importe_total')
            ->assertJsonPath('diagnostics.sales_with_opo_for_importe_total', 1)
            ->assertJsonPath('diagnostics.sum_opo_for_importe_total_sales', 25000);
    }

    public function test_amount_zero_no_calcula_importe_roas_ni_roi_y_expone_estado_admin(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-zero-amount',
            'campaign_name' => 'Campaign Zero Amount',
            'ad_id' => 'ad-zero-amount',
            'spend' => 500,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-zero-amount',
            'name' => 'Lead Zero Amount',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campaign Zero Amount',
            'acquired_id' => 'ad-zero-amount',
            'converted_opportunity_id' => '006-zero-amount',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-zero-amount',
            'name' => 'Oportunidad Zero Amount',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'amount' => 0,
            'opo_for_importe_total' => 0,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            30
        );

        $this->getJson('/informes/campanas/data/summary?'.$this->query())
            ->assertOk()
            ->assertJsonPath('kpis.sales', 1)
            ->assertJsonPath('kpis.sale_amount', null)
            ->assertJsonPath('kpis.roas', null)
            ->assertJsonPath('kpis.estimated_roi', null)
            ->assertJsonPath('diagnostics.amount_field_status', 'exists_but_zero')
            ->assertJsonPath('diagnostics.sale_amount_field_used', 'none')
            ->assertJsonPath('diagnostics.attributed_sales_with_amount', 0);
    }

    public function test_sale_amount_resolver_lee_raw_payload_case_insensitive_y_decimal_formateado(): void
    {
        $opportunity = SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-raw-importe',
            'name' => 'Oportunidad Raw Importe',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'amount' => 0,
            'opo_for_importe_total' => null,
            'raw_payload' => [
                'OPO_FOR_Importe_total__C' => '12.345,67',
            ],
        ]);

        $this->assertSame(12345.67, app(CampaignSaleAmountResolver::class)->resolve($opportunity->fresh()));
    }

    private function query(): string
    {
        return http_build_query([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'attribution_window_days' => 30,
        ]);
    }

    private function metricRow(array $overrides = []): array
    {
        $attributes = array_merge([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-1',
            'campaign_name' => 'Campana',
            'campaign_status' => 'ENABLED',
            'campaign_effective_status' => 'ACTIVE',
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
