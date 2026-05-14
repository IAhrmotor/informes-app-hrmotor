<?php

namespace Tests\Feature;

use App\Models\MasterCallDelegationMapping;
use App\Models\MasterDelegation;
use App\Models\MasterFormSenderMapping;
use App\Models\MasterPortal;
use App\Models\SalesforceActivity;
use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceLeadActivitySummaryService;
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
        $this->commercial('005-worker', 'Comercial Worker', 'Compra/Venta');
        $this->commercial('005-discard', 'Comercial Descarte', 'Comerciales Partner Community');
        $this->commercial('005-owner', 'Comercial Owner', 'Compra/Venta');
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
    }

    public function test_portales_agrupa_y_calcula_llamadas_formularios_conversion_y_descarte(): void
    {
        MasterPortal::create(['portal_original' => 'Google Maps', 'portal_group' => 'Google', 'is_active' => true]);

        $this->lead('00Q1', 'Convertido', ['medio_nuevo' => 'Llamada', 'fuente_nuevo' => 'Google Maps']);
        $this->lead('00Q2', 'Descartado', ['medio_nuevo' => 'Formulario', 'portal_text' => 'Google Maps']);
        $this->lead('00Q3', 'Potencial', ['medio_nuevo' => 'Formulario', 'portal_text' => 'Google Maps']);

        $row = collect($this->getJson('/informes/leads/data/portals')->json('items'))
            ->firstWhere('portal', 'Google Maps');

        $this->assertSame('Google', $row['grupo_portal']);
        $this->assertSame(3, $row['leads_totales']);
        $this->assertSame(1, $row['llamadas']);
        $this->assertSame(2, $row['formularios']);
        $this->assertSame(1, $row['convertidos']);
        $this->assertSame(33.33, $row['conversion_pct']);
        $this->assertSame(1, $row['descartados']);
    }

    public function test_delegaciones_usa_mappings_y_sin_clasificar_si_no_hay_mapping(): void
    {
        MasterDelegation::create(['delegation_name' => 'HR MOTOR MADRID', 'commercial_group' => 'Madrid', 'is_active' => true]);
        MasterCallDelegationMapping::create([
            'portal_original' => 'Google Maps',
            'received_value' => 'Madrid',
            'type' => 'Delegación',
            'delegation_name' => 'HR MOTOR MADRID',
            'commercial_group' => 'Madrid',
            'status' => 'active',
        ]);
        MasterFormSenderMapping::create([
            'portal_original' => 'Web',
            'sender_email' => 'leadsmadrid@hrmotor.com',
            'type' => 'Delegación',
            'delegation_name' => 'HR MOTOR MADRID',
            'commercial_group' => 'Madrid',
            'status' => 'active',
        ]);

        $this->lead('00Q1', 'Potencial', [
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Google Maps',
            'delegacion_encargada_text' => 'Madrid',
        ]);
        $this->lead('00Q2', 'Potencial', [
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
            'remitente_lead' => 'leadsmadrid@hrmotor.com',
        ]);
        $this->lead('00Q3', 'Potencial', ['medio_nuevo' => 'Formulario', 'portal_text' => 'Sin mapa']);

        $rows = collect($this->getJson('/informes/leads/data/delegations')->json('items'));

        $this->assertSame(2, $rows->firstWhere('delegacion', 'HR MOTOR MADRID')['leads_totales']);
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
        $conversion = collect($response->json('comparativa'))->firstWhere('key', 'conversion_pct');
        $this->assertEquals(50.0, $conversion['periodo_actual']);
        $this->assertEquals(0.0, $conversion['periodo_comparado']);
        $this->assertEquals(50.0, $conversion['diferencia']);
    }

    private function commercial(string $id, string $name, string $profile, bool $active = true): void
    {
        SalesforceUser::create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => $profile,
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
