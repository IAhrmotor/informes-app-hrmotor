<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CallsUpdatedDashboardBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-25 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_summary_no_expone_switchboard_y_suma_llamada_directa_como_comercial_directo(): void
    {
        foreach (range(1, 10) as $index) {
            $this->callRow('direct-null-'.$index, [
                'portales_raw' => null,
                'call_origin' => 'commercial_direct',
                'portal_resolved' => 'Comercial directo',
            ]);
        }

        foreach (range(1, 20) as $index) {
            $this->callRow('direct-legacy-'.$index, [
                'portales_raw' => 'Llamada directa',
                'call_origin' => 'switchboard',
                'portal_resolved' => 'Llamada directa',
            ]);
        }

        foreach (range(1, 5) as $index) {
            $this->callRow('portal-'.$index, [
                'portales_raw' => 'Web Pamplona',
                'call_origin' => 'portal',
                'portal_resolved' => 'Web',
            ]);
        }

        $kpis = $this->getJson('/informes/llamadas/data/summary')
            ->assertOk()
            ->json('kpis');

        $this->assertSame(35, $kpis['total_calls']);
        $this->assertSame(30, $kpis['commercial_direct_calls']);
        $this->assertSame(5, $kpis['portal_calls']);
        $this->assertArrayNotHasKey('switchboard_calls', $kpis);
    }

    public function test_portales_excluye_comercial_directo_y_llamada_directa(): void
    {
        $this->callRow('direct-null', [
            'portales_raw' => null,
            'call_origin' => 'commercial_direct',
            'portal_resolved' => 'Comercial directo',
        ]);
        $this->callRow('direct-legacy', [
            'portales_raw' => 'Llamada directa',
            'call_origin' => 'switchboard',
            'portal_resolved' => 'Llamada directa',
        ]);
        $this->callRow('web', [
            'portales_raw' => 'Web Pamplona',
            'call_origin' => 'portal',
            'portal_resolved' => 'Web',
        ]);
        $this->callRow('maps', [
            'portales_raw' => 'Google Maps Gijón',
            'call_origin' => 'portal',
            'portal_resolved' => 'Google Maps',
        ]);

        $portals = collect($this->getJson('/informes/llamadas/data/portals')->assertOk()->json('items'))
            ->pluck('portal')
            ->all();

        $this->assertEqualsCanonicalizing(['Web', 'Google Maps'], $portals);
        $this->assertNotContains('Comercial directo', $portals);
        $this->assertNotContains('Llamada directa', $portals);
    }

    public function test_agentes_se_agrupan_en_cuatro_cuadros_y_excluyen_sistema(): void
    {
        $this->callRow('commercial', ['operational_user_name' => 'Comercial Uno', 'operational_team' => 'commercial']);
        $this->callRow('customer', ['operational_user_name' => 'Carolina Gayarre', 'operational_team' => 'customer_service']);
        $this->callRow('contact', ['operational_user_name' => 'Vanesa German', 'operational_team' => 'contact_center']);
        $this->callRow('appraiser', ['operational_user_name' => 'Tasador Uno', 'operational_team' => 'unclassified']);
        $this->callRow('system', [
            'operational_user_name' => 'Platform Integration User',
            'operational_team' => 'system',
            'owner_profile_name' => 'System Administrator',
        ]);

        $payload = $this->getJson('/informes/llamadas/data/agents')->assertOk()->json();

        $this->assertSame('Comercial Uno', $payload['commercials'][0]['user_name']);
        $this->assertSame('Carolina Gayarre', $payload['customer_service'][0]['user_name']);
        $this->assertSame('Vanesa German', $payload['contact_center'][0]['user_name']);
        $this->assertSame('Tasador Uno', $payload['appraisers'][0]['user_name']);
        $this->assertEmpty(collect($payload['agents'])->where('user_name', 'Platform Integration User')->all());
    }

    public function test_usuarios_especiales_pasan_a_atencion_al_cliente(): void
    {
        foreach (['Vanessa SanJuan', 'Vanessa San Juan', 'Vanesa SanJuan', 'Callcenter Fontellas', 'Call Center Fontellas'] as $index => $name) {
            $this->callRow('special-'.$index, [
                'operational_user_name' => $name,
                'operational_team' => 'unclassified',
            ]);
        }

        $names = collect($this->getJson('/informes/llamadas/data/agents')->assertOk()->json('customer_service'))
            ->pluck('user_name')
            ->all();

        $this->assertContains('Vanessa SanJuan', $names);
        $this->assertContains('Vanessa San Juan', $names);
        $this->assertContains('Vanesa SanJuan', $names);
        $this->assertContains('Callcenter Fontellas', $names);
        $this->assertContains('Call Center Fontellas', $names);
    }

    public function test_usuarios_sistema_cuentan_en_summary_pero_no_en_tablas_operativas(): void
    {
        foreach (['Carlos Torres', 'Platform Integration User', 'API User'] as $index => $name) {
            $this->callRow('system-name-'.$index, [
                'operational_user_name' => $name,
                'operational_team' => 'system',
            ]);
        }
        $this->callRow('system-profile', [
            'operational_user_name' => 'Administrador',
            'operational_team' => 'unclassified',
            'owner_profile_name' => 'System Administrator',
        ]);

        $this->getJson('/informes/llamadas/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 4);

        $agents = collect($this->getJson('/informes/llamadas/data/agents')->assertOk()->json('agents'))->pluck('user_name');

        $this->assertFalse($agents->contains('Carlos Torres'));
        $this->assertFalse($agents->contains('Platform Integration User'));
        $this->assertFalse($agents->contains('API User'));
        $this->assertFalse($agents->contains('Administrador'));
    }

    public function test_filtro_origen_soporta_comercial_portal_y_switchboard_legacy(): void
    {
        $this->callRow('direct-null', [
            'portales_raw' => null,
            'call_origin' => 'commercial_direct',
            'portal_resolved' => 'Comercial directo',
        ]);
        $this->callRow('direct-legacy', [
            'portales_raw' => 'Llamada directa',
            'call_origin' => 'switchboard',
            'portal_resolved' => 'Llamada directa',
        ]);
        $this->callRow('portal-web', [
            'portales_raw' => 'Web Pamplona',
            'call_origin' => 'portal',
            'portal_resolved' => 'Web',
        ]);

        $this->getJson('/informes/llamadas/data/summary?origin=commercial_direct')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 2);

        $this->getJson('/informes/llamadas/data/summary?origin=portal')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 1);

        $this->getJson('/informes/llamadas/data/summary?origin=switchboard')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 2);
    }

    private function callRow(string $id, array $overrides = []): void
    {
        SalesforceCall::create(array_merge([
            'salesforce_id' => $id,
            'created_date' => '2026-05-20 10:00:00',
            'owner_name' => 'Operativo',
            'owner_profile_name' => 'Standard User',
            'operational_user_name' => 'Operativo',
            'operational_team' => 'commercial',
            'owner_team' => 'commercial',
            'delegation' => 'Alcobendas',
            'zone' => 'Zona Sur y Centro',
            'call_duration_seconds' => 80,
            'adjusted_duration_seconds' => 75,
            'call_origin' => 'commercial_direct',
            'portal_resolved' => 'Comercial directo',
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'direction' => 'inbound',
        ], $overrides));
    }
}
