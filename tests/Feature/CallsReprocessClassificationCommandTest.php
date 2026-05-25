<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CallsReprocessClassificationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reprocesa_origen_portal_duracion_y_equipo_de_llamadas_existentes(): void
    {
        Cache::flush();

        SalesforceCall::create([
            'salesforce_id' => 'legacy-switchboard',
            'created_date' => '2026-05-20 10:00:00',
            'owner_name' => 'Tasador Uno',
            'owner_profile_name' => 'Standard User',
            'operational_user_name' => 'Tasador Uno',
            'operational_team' => 'unclassified',
            'owner_team' => 'unclassified',
            'portales_raw' => 'Llamada directa',
            'call_origin' => 'switchboard',
            'portal_resolved' => 'Llamada directa',
            'portal_resolution_source' => 'switchboard',
            'call_duration_seconds' => 80,
            'adjusted_duration_seconds' => 70,
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'direction' => 'inbound',
        ]);

        SalesforceCall::create([
            'salesforce_id' => 'portal-web',
            'created_date' => '2026-05-20 10:00:00',
            'owner_name' => 'Vanessa San Juan',
            'owner_profile_name' => 'Standard User',
            'operational_user_name' => 'Vanessa San Juan',
            'operational_team' => 'unclassified',
            'owner_team' => 'unclassified',
            'portales_raw' => 'Web Pamplona',
            'call_origin' => 'portal',
            'portal_resolved' => 'Web Pamplona',
            'portal_resolution_source' => 'portales_field',
            'call_duration_seconds' => 80,
            'adjusted_duration_seconds' => 70,
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'direction' => 'inbound',
        ]);

        $this->artisan('reports:reprocess-calls-classification')->assertExitCode(0);

        $direct = SalesforceCall::where('salesforce_id', 'legacy-switchboard')->firstOrFail();
        $portal = SalesforceCall::where('salesforce_id', 'portal-web')->firstOrFail();

        $this->assertSame('commercial_direct', $direct->call_origin);
        $this->assertSame('Comercial directo', $direct->portal_resolved);
        $this->assertSame('commercial_direct', $direct->portal_resolution_source);
        $this->assertSame(75, $direct->adjusted_duration_seconds);
        $this->assertSame('appraiser', $direct->operational_team);

        $this->assertSame('portal', $portal->call_origin);
        $this->assertSame('Web', $portal->portal_resolved);
        $this->assertSame('customer_service', $portal->operational_team);
        $this->assertSame(0, SalesforceCall::where('call_origin', 'switchboard')->count());
        $this->assertGreaterThan(1, Cache::get('salesforce_calls_dashboard_cache_version'));
    }
}
