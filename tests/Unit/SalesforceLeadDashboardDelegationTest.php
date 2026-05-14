<?php

namespace Tests\Unit;

use App\Models\SalesforceUser;
use App\Services\Reports\Leads\SalesforceLeadDashboardDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceLeadDashboardDelegationTest extends TestCase
{
    use RefreshDatabase;

    private SalesforceLeadDashboardDatasetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SalesforceLeadDashboardDatasetService::class);
    }

    public function test_delegacion_lead_prioriza_text_encargada_y_bueno(): void
    {
        $lead = $this->service->decorateLead([
            'status' => 'Potencial',
            'delegacion_encargada_text' => 'Madrid',
            'delegacion_encargada' => 'HR MOTOR VALENCIA',
            'delegacion_encargada_bueno' => 'HR MOTOR ZARAGOZA',
        ]);
        $fallbackEncargada = $this->service->decorateLead([
            'status' => 'Potencial',
            'delegacion_encargada' => 'HR MOTOR VALENCIA',
            'delegacion_encargada_bueno' => 'HR MOTOR ZARAGOZA',
        ]);
        $fallbackBueno = $this->service->decorateLead([
            'status' => 'Potencial',
            'delegacion_encargada_bueno' => 'HR MOTOR ZARAGOZA',
        ]);
        $empty = $this->service->decorateLead(['status' => 'Potencial']);

        $this->assertSame('Madrid General', $lead['lead_delegation']);
        $this->assertSame('Valencia', $fallbackEncargada['lead_delegation']);
        $this->assertSame('Zaragoza', $fallbackBueno['lead_delegation']);
        $this->assertSame('Sin clasificar', $empty['lead_delegation']);
    }

    public function test_delegacion_comercial_sale_del_usuario_responsable_atribuido(): void
    {
        $this->user('005-worker', 'Trabajador', 'HR MOTOR MADRID');
        $this->user('005-discard', 'Descarte', 'HR MOTOR VALENCIA');
        $this->user('005-owner', 'Owner', 'HR MOTOR MADRID');

        $converted = $this->service->decorateLead([
            'status' => 'Convertido',
            'owner_id' => '005-owner',
            'persona_que_trabajo_id' => '005-worker',
        ]);
        $discarded = $this->service->decorateLead([
            'status' => 'Descartado',
            'owner_id' => '005-owner',
            'persona_que_trabajo_id' => '005-worker',
            'propietario_descarte_id' => '005-discard',
        ]);
        $potential = $this->service->decorateLead([
            'status' => 'Potencial',
            'owner_id' => '005-owner',
        ]);

        $this->assertSame('Madrid General', $converted['commercial_delegation']);
        $this->assertSame('Valencia', $discarded['commercial_delegation']);
        $this->assertSame('Madrid General', $potential['commercial_delegation']);
        $this->assertSame('Madrid', $potential['commercial_zone']);
    }

    public function test_actividad_futura_no_cuenta_como_gestionado_en_periodo_historico(): void
    {
        $lead = $this->service->decorateLead(
            ['status' => 'Potencial'],
            ['total_actividades' => 1, 'fecha_ultima_actividad' => '2026-05-10 12:00:00'],
            CarbonImmutable::parse('2026-05-01 23:59:59'),
        );

        $this->assertFalse($lead['is_gestionado']);
        $this->assertTrue($lead['is_potencial_sin_trabajar']);
    }

    private function user(string $id, string $name, string $delegation): void
    {
        SalesforceUser::create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => 'Compra/Venta',
            'user_delegation' => $delegation,
            'is_active' => true,
        ]);
    }
}
