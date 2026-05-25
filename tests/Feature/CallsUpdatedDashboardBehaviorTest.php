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
        $this->assertContains('Callcenter Fontellas', $names);
        $this->assertCount(2, $names);
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
        $zones = $this->getJson('/informes/llamadas/data/delegations')->assertOk()->json('zones');
        $delegations = $this->getJson('/informes/llamadas/data/delegations')->assertOk()->json('delegations');

        $this->assertFalse($agents->contains('Carlos Torres'));
        $this->assertFalse($agents->contains('Platform Integration User'));
        $this->assertFalse($agents->contains('API User'));
        $this->assertFalse($agents->contains('Administrador'));
        $this->assertEmpty($zones);
        $this->assertEmpty($delegations);
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

    public function test_atencion_y_contact_center_aparecen_como_zonas_y_delegaciones_propias(): void
    {
        $this->callRow('customer-service', [
            'operational_user_name' => 'Carolina Gayarre',
            'operational_team' => 'customer_service',
            'delegation' => 'Sin clasificar',
            'zone' => 'Sin clasificar',
        ]);
        $this->callRow('contact-center', [
            'operational_user_name' => 'Vanesa German',
            'operational_team' => 'contact_center',
            'delegation' => 'Sin clasificar',
            'zone' => 'Sin clasificar',
        ]);

        $payload = $this->getJson('/informes/llamadas/data/delegations')->assertOk()->json();
        $zones = collect($payload['zones'])->pluck('zone');
        $delegations = collect($payload['delegations'])->pluck('delegation');

        $this->assertTrue($zones->contains('Atención al Cliente'));
        $this->assertTrue($zones->contains('Contact Center'));
        $this->assertTrue($delegations->contains('Atención al Cliente'));
        $this->assertTrue($delegations->contains('Contact Center'));
        $this->assertFalse($zones->contains('Sin clasificar'));
        $this->assertFalse($delegations->contains('Sin clasificar'));
    }

    public function test_deduplica_contact_center_por_nombre_normalizado(): void
    {
        foreach (['Vanesa German', 'Vanesa Germán', 'AG1 - Vanesa Germán'] as $index => $name) {
            $this->callRow('vanesa-'.$index, [
                'operational_user_name' => $name,
                'destination_agent_name' => $name,
                'operational_team' => 'contact_center',
                'adjusted_duration_seconds' => 30,
            ]);
        }

        foreach (['Yuleidis Garcia', 'Yuleidis García', 'AG23 - Yuleidis García'] as $index => $name) {
            $this->callRow('yuleidis-'.$index, [
                'operational_user_name' => $name,
                'destination_agent_name' => $name,
                'operational_team' => 'contact_center',
                'adjusted_duration_seconds' => 60,
            ]);
        }

        $rows = collect($this->getJson('/informes/llamadas/data/agents')->assertOk()->json('contact_center'));

        $this->assertCount(2, $rows);
        $this->assertSame(3, $rows->firstWhere('user_name', 'Vanesa German')['total_calls']);
        $this->assertSame(3, $rows->firstWhere('user_name', 'Yuleidis Garcia')['total_calls']);
        $this->assertEquals(30.0, $rows->firstWhere('user_name', 'Vanesa German')['average_talk_seconds']);
        $this->assertEquals(60.0, $rows->firstWhere('user_name', 'Yuleidis Garcia')['average_talk_seconds']);
    }

    public function test_summary_devuelve_atendidas_y_perdidas_por_origen(): void
    {
        foreach (range(1, 10) as $index) {
            $this->callRow('direct-answered-'.$index, [
                'portales_raw' => null,
                'call_origin' => 'commercial_direct',
                'call_status' => 'answered',
                'is_answered' => true,
                'is_lost' => false,
            ]);
        }
        foreach (range(1, 5) as $index) {
            $this->callRow('direct-lost-'.$index, [
                'portales_raw' => 'Llamada directa',
                'call_origin' => 'switchboard',
                'call_status' => 'not_answered',
                'is_answered' => false,
                'is_lost' => true,
            ]);
        }
        foreach (range(1, 7) as $index) {
            $this->callRow('portal-answered-'.$index, [
                'portales_raw' => 'Web Pamplona',
                'call_origin' => 'portal',
                'portal_resolved' => 'Web',
                'call_status' => 'answered',
                'is_answered' => true,
                'is_lost' => false,
            ]);
        }
        foreach (range(1, 3) as $index) {
            $this->callRow('portal-lost-'.$index, [
                'portales_raw' => 'Google Maps Gijón',
                'call_origin' => 'portal',
                'portal_resolved' => 'Google Maps',
                'call_status' => 'not_answered',
                'is_answered' => false,
                'is_lost' => true,
            ]);
        }

        $kpis = $this->getJson('/informes/llamadas/data/summary')->assertOk()->json('kpis');

        $this->assertSame(25, $kpis['total_calls']);
        $this->assertSame(17, $kpis['answered_calls']);
        $this->assertSame(8, $kpis['lost_calls']);
        $this->assertSame(15, $kpis['commercial_direct_calls']);
        $this->assertSame(10, $kpis['commercial_direct_answered']);
        $this->assertSame(5, $kpis['commercial_direct_lost']);
        $this->assertSame(10, $kpis['portal_calls']);
        $this->assertSame(7, $kpis['portal_answered']);
        $this->assertSame(3, $kpis['portal_lost']);
    }

    public function test_filtro_zona_incluye_equipos_de_servicio(): void
    {
        $this->callRow('customer-service', [
            'operational_user_name' => 'Carolina Gayarre',
            'operational_team' => 'customer_service',
            'delegation' => 'Sin clasificar',
            'zone' => 'Sin clasificar',
        ]);
        $this->callRow('contact-center', [
            'operational_user_name' => 'Vanesa German',
            'operational_team' => 'contact_center',
            'delegation' => 'Sin clasificar',
            'zone' => 'Sin clasificar',
        ]);

        $this->getJson('/informes/llamadas/data/delegations?zone='.urlencode('Atención al Cliente'))
            ->assertOk()
            ->assertJsonPath('zones.0.zone', 'Atención al Cliente')
            ->assertJsonCount(1, 'zones');

        $this->getJson('/informes/llamadas/data/delegations?zone=Contact+Center')
            ->assertOk()
            ->assertJsonPath('zones.0.zone', 'Contact Center')
            ->assertJsonCount(1, 'zones');
    }

    public function test_no_muestra_centralita_ni_switchboard_en_front_o_filtros(): void
    {
        $this->get('/informes/llamadas')
            ->assertOk()
            ->assertDontSee('Centralita')
            ->assertDontSee('switchboard');

        $origins = collect($this->getJson('/informes/llamadas/data/summary')->assertOk()->json('filters.origins'));

        $this->assertFalse($origins->pluck('name')->contains('Centralita'));
        $this->assertFalse($origins->pluck('id')->contains('switchboard'));
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
