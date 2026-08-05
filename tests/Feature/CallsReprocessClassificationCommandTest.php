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

        $this->artisan('reports:reprocess-calls-classification', [
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
            '--reason' => 'Prueba de regresión controlada',
        ])->assertExitCode(0);

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
        $this->assertSame('Atención al Cliente', $portal->delegation);
        $this->assertSame('Atención al Cliente', $portal->zone);
        $this->assertSame(0, SalesforceCall::where('call_origin', 'switchboard')->count());
        $this->assertGreaterThan(1, Cache::get('salesforce_calls_dashboard_cache_version'));
        $this->assertDatabaseCount('salesforce_call_classification_history', 2);
    }

    public function test_exige_periodo_y_el_modo_simulacion_no_modifica(): void
    {
        $this->artisan('reports:reprocess-calls-classification')->assertExitCode(1);

        SalesforceCall::create([
            'salesforce_id' => 'dry-call',
            'created_date' => '2026-05-20 10:00:00',
            'call_status' => 'not_answered',
            'result_raw' => 'ANSWERED',
        ]);

        $this->artisan('reports:reprocess-calls-classification', [
            '--from' => '2026-05-01', '--to' => '2026-05-31', '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame('not_answered', SalesforceCall::where('salesforce_id', 'dry-call')->value('call_status'));
        $this->assertDatabaseCount('salesforce_call_classification_history', 0);
    }

    public function test_reprocesado_considera_respondido_por_como_atendida_sin_pisar_abandoned(): void
    {
        foreach ([
            'answered-by' => ['description' => "Respondido por: Agente Uno", 'result_raw' => null],
            'abandoned' => ['description' => "Resultado: ABANDONED\nRespondido por: Agente Uno", 'result_raw' => 'ABANDONED'],
        ] as $id => $values) {
            SalesforceCall::query()->create([
                'salesforce_id' => $id,
                'created_date' => '2026-05-20 10:00:00',
                'description' => $values['description'],
                'result_raw' => $values['result_raw'],
                'call_status' => 'not_answered',
                'is_answered' => false,
                'is_lost' => true,
            ]);
        }

        $this->artisan('reports:reprocess-calls-classification', [
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
            '--reason' => 'Validacion de Respondido por en historico.',
        ])->assertExitCode(0);

        $this->assertTrue((bool) SalesforceCall::query()->where('salesforce_id', 'answered-by')->value('is_answered'));
        $this->assertFalse((bool) SalesforceCall::query()->where('salesforce_id', 'abandoned')->value('is_answered'));
    }
}
