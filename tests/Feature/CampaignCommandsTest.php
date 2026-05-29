<?php

namespace Tests\Feature;

use App\Models\CampaignAttribution;
use App\Models\SalesforceLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignCommandsTest extends TestCase
{
    use RefreshDatabase;

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
