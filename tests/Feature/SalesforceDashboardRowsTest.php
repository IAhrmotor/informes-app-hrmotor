<?php

namespace Tests\Feature;

use App\Models\MasterPortal;
use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceDashboardRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-13 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_comerciales_solo_muestra_usuarios_activos_de_perfiles_permitidos_y_resuelve_gestor(): void
    {
        $this->commercial('005-worker', 'Comercial Worker', 'Compra/Venta', true, 'HR MOTOR TORREJON');
        $this->commercial('005-discard', 'Comercial Descarte', 'Comerciales Partner Community', true, 'HR MOTOR TORREJON');
        $this->commercial('005-owner', 'Comercial Owner', 'Compra/Venta', true, 'HR MOTOR TORREJON');
        $this->commercial('005-api', 'API User', 'Administrador del sistema');
        $this->commercial('005-inactive', 'Inactivo', 'Compra/Venta', false);

        $this->lead('00Q1', 'Convertido', ['persona_que_trabajo_id' => '005-worker', 'persona_que_trabajo_name' => 'Comercial Worker']);
        $this->lead('00Q2', 'Descartado', ['propietario_descarte_id' => '005-discard', 'propietario_descarte_name' => 'Comercial Descarte']);
        $this->lead('00Q3', 'Potencial', ['owner_id' => '005-owner', 'owner_name' => 'Comercial Owner']);
        $this->lead('00Q4', 'Potencial', ['owner_id' => '005-api', 'owner_name' => 'API User']);
        $this->lead('00Q5', 'Potencial', ['owner_id' => '005-inactive', 'owner_name' => 'Inactivo']);

        $response = $this->getJson('/informes/leads/data/commercials');
        $names = collect($response->json('items'))->pluck('comercial')->all();

        $this->assertContains('Comercial Worker', $names);
        $this->assertContains('Comercial Descarte', $names);
        $this->assertContains('Comercial Owner', $names);
        $this->assertNotContains('API User', $names);
        $this->assertNotContains('Inactivo', $names);

        $worker = collect($response->json('items'))->firstWhere('comercial', 'Comercial Worker');
        $this->assertSame('Torrejón', $worker['commercial_delegation']);
        $this->assertSame('Zona Sur y Centro', $worker['zone']);
    }

    public function test_portales_agrupa_y_calcula_llamadas_formularios_conversion_y_descarte_sin_grupo_visible(): void
    {
        MasterPortal::create(['portal_original' => 'Google Maps', 'portal_group' => 'Google', 'is_active' => true]);

        $this->lead('00Q1', 'Convertido', ['medio_nuevo' => 'Llamada', 'fuente_nuevo' => 'Google Maps']);
        $this->lead('00Q2', 'Descartado', ['medio_nuevo' => 'Formulario', 'portal_text' => 'Google Maps']);
        $this->lead('00Q3', 'Potencial', ['medio_nuevo' => 'Formulario', 'portal_text' => 'Google Maps']);

        $row = collect($this->getJson('/informes/leads/data/portals')->json('items'))
            ->firstWhere('portal', 'Google Maps');

        $this->assertArrayNotHasKey('grupo_portal', $row);
        $this->assertSame(3, $row['leads_totales']);
        $this->assertSame(1, $row['llamadas']);
        $this->assertSame(2, $row['formularios']);
        $this->assertSame(1, $row['convertidos']);
        $this->assertSame(33.33, $row['conversion_pct']);
        $this->assertSame(1, $row['descartados']);
    }

    public function test_delegaciones_usa_prioridad_salesforce_y_sin_clasificar_si_no_hay_valor(): void
    {
        $this->commercial('005-owner', 'Comercial Owner', 'Compra/Venta', true, 'HR MOTOR TORREJON');

        $this->lead('00Q1', 'Potencial', [
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Google Maps',
            'delegacion_encargada_text' => 'Madrid',
        ]);
        $this->lead('00Q2', 'Potencial', [
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
            'delegacion_encargada' => 'HR MOTOR MADRID',
        ]);
        $this->lead('00Q3', 'Potencial', ['medio_nuevo' => 'Formulario', 'portal_text' => 'Sin mapa']);

        $rows = collect($this->getJson('/informes/leads/data/delegations')->json('items'));

        $this->assertSame(2, $rows->firstWhere('delegacion', 'Madrid General')['leads_totales']);
        $this->assertSame(1, $rows->firstWhere('delegacion', 'Sin clasificar')['potenciales_sin_trabajar']);
    }

    public function test_summary_compara_periodo_actual_y_comparado(): void
    {
        $this->lead('00Q1', 'Convertido', ['created_date' => '2026-05-10 10:00:00']);
        $this->lead('00Q2', 'Potencial', ['created_date' => '2026-05-11 10:00:00']);
        $this->lead('00Q3', 'Descartado', ['created_date' => '2026-04-01 10:00:00']);

        $response = $this->getJson('/informes/leads/data/summary');

        $this->assertSame(2, $response->json('kpis.leads_totales'));
        $this->assertSame(1, $response->json('kpis.convertidos'));
        $conversion = collect($response->json('comparativa'))->firstWhere('key', 'convertidos');
        $this->assertEquals(50.0, $conversion['periodo_actual_pct']);
        $this->assertEquals(0.0, $conversion['periodo_comparado_pct']);
        $this->assertEquals(50.0, $conversion['diferencia_pct_puntos']);
    }

    public function test_filtros_y_tabla_delegaciones_no_exponen_brutos_ni_emails(): void
    {
        $this->lead('00Q10', 'Potencial', ['delegacion_encargada_text' => 'leadsmadrid@hrmotor.com']);
        $this->lead('00Q11', 'Potencial', ['delegacion_encargada_text' => 'Zona Madrid']);
        $this->lead('00Q12', 'Potencial', ['delegacion_encargada_text' => 'Tudela']);
        $this->lead('00Q13', 'Potencial', ['delegacion_encargada_text' => 'Web Alicante']);
        $this->lead('00Q14', 'Potencial', ['delegacion_encargada_text' => 'Llamada directa']);

        $summary = $this->getJson('/informes/leads/data/summary');
        $leadDelegations = $summary->json('filters.lead_delegations');
        $commercialDelegations = $summary->json('filters.commercial_delegations');
        $zones = $summary->json('filters.zones');

        $this->assertContains('Madrid General', $leadDelegations);
        $this->assertContains('Fontellas', $leadDelegations);
        $this->assertContains('Sin clasificar', $leadDelegations);
        $this->assertNotContains('leadsmadrid@hrmotor.com', $leadDelegations);
        $this->assertNotContains('Zona Madrid', $leadDelegations);
        $this->assertNotContains('Tudela', $leadDelegations);
        $this->assertNotContains('Web Alicante', $leadDelegations);
        $this->assertNotContains('Llamada directa', $leadDelegations);
        $this->assertArrayNotHasKey('lead_groups', $summary->json('filters'));
        $this->assertNotContains('Grupo Madrid', $commercialDelegations);
        $this->assertNotContains('Madrid General', $commercialDelegations);
        $this->assertContains('Zona Sur y Centro', $zones);
        $this->assertContains('Zona Norte', $zones);
        $this->assertNotContains('Tudela', $zones);

        $rows = collect($this->getJson('/informes/leads/data/delegations')->json('items'));

        $this->assertSame(2, $rows->firstWhere('delegacion', 'Madrid General')['leads_totales']);
        $this->assertSame(1, $rows->firstWhere('delegacion', 'Fontellas')['leads_totales']);
        $this->assertSame(2, $rows->firstWhere('delegacion', 'Sin clasificar')['leads_totales']);
    }

    private function commercial(string $id, string $name, string $profile, bool $active = true, ?string $delegation = null): void
    {
        SalesforceUser::create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => $profile,
            'user_delegation' => $delegation,
            'is_active' => $active,
        ]);
    }

    private function lead(string $id, string $status, array $overrides = []): SalesforceLead
    {
        return SalesforceLead::create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-05-10 10:00:00',
            'status' => $status,
            'owner_id' => $overrides['owner_id'] ?? '005-owner',
            'owner_name' => $overrides['owner_name'] ?? 'Comercial Owner',
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
        ], $overrides));
    }
}
