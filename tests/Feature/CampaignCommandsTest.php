<?php

namespace Tests\Feature;

use App\Models\CampaignAttribution;
use App\Models\CampaignSalesforceLead;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Campaigns\CampaignAttributionBuilderService;
use App\Services\Campaigns\CampaignLeadSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_lead_sync_mapper_guarda_campos_de_adquisicion(): void
    {
        $service = new CampaignLeadSyncService($this->createMock(SalesforceClient::class));

        $row = $service->mapRecord([
            'Id' => '00Q-campaign',
            'CreatedDate' => '2026-05-10T10:00:00.000+0000',
            'Name' => 'Lead Campana',
            'Status' => 'Nuevo',
            'OwnerId' => '005-owner',
            'Owner' => ['Name' => 'Gestor Campana'],
            'Phone' => '+34 600 000 001',
            'MobilePhone' => '600000002',
            'Email' => 'lead@example.com',
            'IsConverted' => true,
            'ConvertedDate' => '2026-05-11T10:00:00.000+0000',
            'ConvertedAccountId' => '001-account',
            'ConvertedContactId' => '003-contact',
            'ConvertedOpportunityId' => '006-opportunity',
            'LEA_SEL_Fuente_Origen__c' => 'Meta',
            'LEA_SEL_Medio_Origen__c' => 'Formulario',
            'Campa_a_Adquirida__c' => 'Campana Meta',
            'Id_Adquirido__c' => 'ad-123',
            'Contenido_Adquirido__c' => 'creative-123',
            'LEA_BUS_Vehiculo_de_interes__c' => 'Vehiculo A',
            'Delegacion_Encargada_Text__c' => 'Alcobendas',
            'Delegacion_Encargada__c' => 'deleg-1',
            'Delegacion_Encargada_Bueno__c' => 'Alcobendas',
        ], now());

        $this->assertSame('00Q-campaign', $row['salesforce_id']);
        $this->assertSame('Gestor Campana', $row['owner_name']);
        $this->assertSame('Meta', $row['fuente_origen']);
        $this->assertSame('Formulario', $row['medio_origen']);
        $this->assertSame('Campana Meta', $row['campaign_acquired']);
        $this->assertSame('ad-123', $row['acquired_id']);
        $this->assertSame('creative-123', $row['content_acquired']);
        $this->assertSame('Alcobendas', $row['delegacion_encargada_text']);
    }

    public function test_campaign_lead_sync_fresh_no_borra_salesforce_leads_generales(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-general',
            'name' => 'Lead general',
            'created_date' => now()->subDay(),
            'status' => 'Potencial',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
        ]);

        CampaignSalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-old',
            'name' => 'Lead campana antiguo',
            'created_date' => now()->subDay(),
            'campaign_acquired' => 'Campana anterior',
        ]);

        $client = $this->createMock(SalesforceClient::class);
        $client->method('query')->willReturn([]);

        $result = (new CampaignLeadSyncService($client))->sync(now()->subDays(3), now(), true);

        $this->assertSame('campaign_salesforce_leads', $result['table']);
        $this->assertSame(1, $result['deleted']);
        $this->assertDatabaseHas('salesforce_leads', ['salesforce_id' => '00Q-general']);
        $this->assertDatabaseMissing('campaign_salesforce_leads', ['salesforce_id' => '00Q-campaign-old']);
    }

    public function test_build_attribution_usa_salesforce_leads_y_persiste_source_campaign_name(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-sale',
            'name' => 'Lead venta',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana Salesforce',
        ]);

        $result = app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertSame('salesforce_leads', $result['lead_source_table']);
        $this->assertSame(1, $result['saved_attributions']);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'lead_id' => '00Q-campaign-sale',
            'platform' => 'salesforce',
            'campaign_name' => 'Campana Salesforce',
            'source_campaign_name' => 'Campana Salesforce',
            'campaign_type' => 'venta',
            'opportunity_id' => null,
        ]);
    }

    public function test_rebuild_del_mismo_periodo_no_pierde_oportunidades_ya_atribuidas(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-rebuild-opportunity',
            'name' => 'Lead rebuild oportunidad',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Tasacion',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'TASADOR LANDING SEARCH 1',
            'converted_opportunity_id' => '006-rebuild-opportunity',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-rebuild-opportunity',
            'name' => 'Oportunidad rebuild',
            'created_date' => '2026-05-12 10:00:00',
            'record_type_name' => 'Tasacion',
            'stage_name' => 'Generar contrato',
            'account_id' => '001-rebuild-opportunity',
            'reservation' => false,
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-20',
            'opo_for_importe_total' => -9000,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-rebuild-opportunity',
            'opportunity_id' => '006-rebuild-opportunity',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'lead_id' => '00Q-rebuild-opportunity',
            'opportunity_id' => '006-rebuild-opportunity',
            'has_purchase' => true,
        ]);
    }

    public function test_sync_commands_no_fallan_sin_credenciales(): void
    {
        config()->set('services.meta_ads.access_token', null);
        config()->set('services.meta_ads.ad_account_ids', []);
        config()->set('services.google_ads.developer_token', null);
        config()->set('services.google_ads.customer_ids', []);

        $this->artisan('campaigns:sync-meta', ['--days' => 1])
            ->expectsOutputToContain('Meta Ads configurado: no')
            ->assertExitCode(0);

        $this->artisan('campaigns:sync-google', ['--days' => 1])
            ->expectsOutputToContain('Google Ads configurado: no')
            ->assertExitCode(0);
    }

    public function test_build_attribution_command_excluye_leads_sin_atribucion_clara(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-clear',
            'name' => 'Lead claro',
            'created_date' => now()->subDay(),
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana real',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
        ]);

        foreach ([
            ['00Q-none', 'none'],
            ['00Q-literal', 'Campa_a_Adquirida__c'],
            ['00Q-tasador', 'tasador'],
            ['00Q-ren2click', 'landing ren2click'],
            ['00Q-hrrenting', 'marca hrrenting'],
        ] as [$id, $campaign]) {
            SalesforceLead::query()->create([
                'salesforce_id' => $id,
                'name' => 'Lead '.$campaign,
                'created_date' => now()->subDay(),
                'status' => 'Potencial',
                'record_type_name' => 'Venta',
                'owner_id' => '005-real',
                'owner_name' => 'Comercial Real',
                'campaign_acquired' => $campaign,
            ]);
        }

        $this->artisan('campaigns:build-attribution', ['--days' => 3])
            ->expectsOutputToContain('Leads candidatos validos: 1')
            ->expectsOutputToContain('Leads procesados: 1')
            ->assertExitCode(0);

        $this->assertSame(1, CampaignAttribution::query()->count());
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-clear',
            'campaign_name' => 'Campana real',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'lead_id' => '00Q-clear',
            'campaign_name' => 'Campana real',
            'source_campaign_name' => 'Campana real',
        ]);
        $this->assertDatabaseMissing('campaign_lead_attributions', ['lead_id' => '00Q-tasador']);
        $this->assertDatabaseMissing('campaign_lead_attributions', ['lead_id' => '00Q-ren2click']);
        $this->assertDatabaseMissing('campaign_lead_attributions', ['lead_id' => '00Q-hrrenting']);
    }

    public function test_build_attribution_respeta_oportunidades_ya_atribuidas_fuera_de_rango(): void
    {
        CampaignAttribution::query()->create([
            'lead_id' => '00Q-old',
            'opportunity_id' => '006-shared',
            'platform' => 'salesforce',
            'campaign_name' => 'Campana antigua',
            'lead_created_at' => '2026-03-10 10:00:00',
            'has_opportunity' => true,
            'attribution_method' => 'salesforce_only',
            'attribution_confidence' => 'low',
            'match_status' => 'salesforce_only',
            'campaign_source_type' => 'salesforce_campaign_without_spend',
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-new',
            'name' => 'Lead nuevo',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana nueva',
            'converted_opportunity_id' => '006-shared',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-shared',
            'name' => 'Opportunity compartida',
            'created_date' => '2026-05-12 10:00:00',
            'stage_name' => 'Abierta',
            'record_type_name' => 'Venta',
        ]);

        $result = app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertSame(1, $result['saved_attributions']);
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-old',
            'opportunity_id' => '006-shared',
        ]);
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-new',
            'opportunity_id' => null,
            'campaign_name' => 'Campana nueva',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'lead_id' => '00Q-new',
            'opportunity_id' => null,
            'campaign_name' => 'Campana nueva',
            'source_campaign_name' => 'Campana nueva',
        ]);
    }

    public function test_tasacion_valida_no_convertida_entra_y_preserva_source_campaign_name(): void
    {
        foreach ([
            'TASADOR_LANDING_SEARCH_1',
            'Expiey_Leads_Geo_Tasación',
            'Expiey_Leads_Geo_Tasación_Nuevas Ubicaciones',
        ] as $index => $campaign) {
            SalesforceLead::query()->create([
                'salesforce_id' => '00Q-tasacion-'.$index,
                'name' => 'Lead '.$campaign,
                'created_date' => '2026-05-10 10:00:00',
                'status' => 'Potencial',
                'record_type_name' => 'Tasacion',
                'owner_id' => '005-real',
                'owner_name' => 'Comercial Real',
                'campaign_acquired' => $campaign,
            ]);
        }

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseHas('campaign_lead_attributions', [
            'lead_id' => '00Q-tasacion-0',
            'campaign_type' => 'tasacion',
            'source_campaign_name' => 'TASADOR_LANDING_SEARCH_1',
            'opportunity_id' => null,
            'has_purchase' => false,
            'has_sale' => false,
            'has_reservation' => false,
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'lead_id' => '00Q-tasacion-1',
            'campaign_type' => 'tasacion',
            'source_campaign_name' => 'Expiey_Leads_Geo_Tasación',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'lead_id' => '00Q-tasacion-2',
            'campaign_type' => 'tasacion',
            'source_campaign_name' => 'Expiey_Leads_Geo_Tasación_Nuevas Ubicaciones',
        ]);
    }

    public function test_rebuild_de_periodo_elimina_atribuciones_obsoletas_y_mantiene_solo_leads_validos_actuales(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-rebuild',
            'name' => 'Lead Rebuild',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana vigente',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseHas('campaign_lead_attributions', [
            'lead_id' => '00Q-rebuild',
            'source_campaign_name' => 'Campana vigente',
        ]);

        SalesforceLead::query()->where('salesforce_id', '00Q-rebuild')->update([
            'campaign_acquired' => 'tasador',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseMissing('campaign_lead_attributions', [
            'lead_id' => '00Q-rebuild',
        ]);
    }

    public function test_debug_attribution_command_resume_validos_y_excluidos(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-debug-venta',
            'name' => 'Lead Debug Venta',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana Debug Venta',
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-debug-excluded',
            'name' => 'Lead Debug Excluded',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Tasacion',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'tasador',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->artisan('campaigns:debug-attribution', [
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
        ])
            ->expectsOutputToContain('[Todas] validos=1 | atribuciones=1')
            ->expectsOutputToContain('tasador_exact')
            ->expectsOutputToContain('Campana Debug Venta')
            ->assertExitCode(0);
    }

    public function test_refresh_campaigns_store_crea_snapshot(): void
    {
        $this->artisan('reports:refresh-campaigns', ['--days' => 1, '--store' => true])
            ->expectsOutputToContain('Snapshot id:')
            ->assertExitCode(0);

        $this->assertDatabaseCount('campaign_report_snapshots', 1);
    }
}
