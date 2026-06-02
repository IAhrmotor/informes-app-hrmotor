<?php

namespace Tests\Feature;

use App\Models\CampaignAttribution;
use App\Models\CampaignSalesforceLead;
use App\Models\SalesforceLead;
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

    public function test_build_attribution_lee_campaign_salesforce_leads_si_tiene_datos(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-general-only',
            'name' => 'Lead general',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
        ]);

        CampaignSalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-source',
            'name' => 'Lead procedencia',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Google Maps',
            'medio_origen' => 'Llamada',
        ]);

        CampaignSalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-name',
            'name' => 'Lead campana',
            'created_date' => '2026-05-10 11:00:00',
            'status' => 'Potencial',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campana Salesforce',
        ]);

        $result = app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            30
        );

        $this->assertSame('campaign_salesforce_leads', $result['lead_source_table']);
        $this->assertSame(2, $result['saved_attributions']);
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-campaign-source',
            'campaign_source_type' => 'salesforce_origin',
            'source_acquired' => 'Google Maps',
            'medium_acquired' => 'Llamada',
        ]);
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-campaign-name',
            'campaign_source_type' => 'salesforce_campaign_without_spend',
            'campaign_name' => 'Campana Salesforce',
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

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-none',
            'name' => 'Lead none',
            'created_date' => now()->subDay(),
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'none',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-literal',
            'name' => 'Lead literal',
            'created_date' => now()->subDay(),
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campa_a_Adquirida__c',
            'acquired_id' => 'Id_Adquirido__c',
            'content_acquired' => 'Contenido_Adquirido__c',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
        ]);

        $this->artisan('campaigns:build-attribution', ['--days' => 3, '--window' => 30])
            ->expectsOutputToContain('Leads candidatos validos: 1')
            ->expectsOutputToContain('Leads procesados: 1')
            ->assertExitCode(0);

        $this->assertSame(1, CampaignAttribution::query()->count());
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-clear',
            'campaign_name' => 'Campana real',
        ]);
    }

    public function test_refresh_campaigns_store_crea_snapshot(): void
    {
        $this->artisan('reports:refresh-campaigns', ['--days' => 1, '--window' => 30, '--store' => true])
            ->expectsOutputToContain('Snapshot id:')
            ->assertExitCode(0);

        $this->assertDatabaseCount('campaign_report_snapshots', 1);
    }
}
