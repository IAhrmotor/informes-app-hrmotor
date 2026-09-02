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

    public function test_delegacion_lead_prioriza_bueno_y_encargada(): void
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
        ]);
        $fallbackBueno = $this->service->decorateLead([
            'status' => 'Potencial',
            'delegacion_encargada_bueno' => 'HR MOTOR ZARAGOZA',
        ]);
        $empty = $this->service->decorateLead(['status' => 'Potencial']);

        $this->assertSame('Zaragoza', $lead['lead_delegation']);
        $this->assertSame('Valencia', $fallbackEncargada['lead_delegation']);
        $this->assertSame('Zaragoza', $fallbackBueno['lead_delegation']);
        $this->assertSame('Sin clasificar', $empty['lead_delegation']);
    }

    public function test_delegacion_nueva_gana_incluso_en_exposicion(): void
    {
        $lead = $this->service->decorateLead([
            'status' => 'Potencial',
            'record_type_name' => 'Exposición',
            'delegation_origin_new' => 'HR MOTOR MADRID',
            'delegacion_encargada_bueno' => 'HR MOTOR ZARAGOZA',
            'owner_delegation' => 'HR MOTOR VALENCIA',
        ]);

        $this->assertSame('Madrid General', $lead['lead_delegation']);
        $this->assertSame('Delegacion_procedencia__c', $lead['delegation_resolution']['source_field']);
    }

    public function test_scope_de_acceso_conserva_delegacion_legacy_mientras_la_visible_usa_la_nueva(): void
    {
        $legacyValencia = $this->service->decorateLead([
            'status' => 'Potencial',
            'delegation_origin_new' => 'HR MOTOR MADRID',
            'delegacion_encargada_bueno' => 'HR MOTOR VALENCIA',
        ]);
        $legacyMadrid = $this->service->decorateLead([
            'status' => 'Potencial',
            'delegation_origin_new' => 'HR MOTOR VALENCIA',
            'delegacion_encargada_bueno' => 'HR MOTOR MADRID',
        ]);

        $this->assertSame('Madrid General', $legacyValencia['lead_delegation']);
        $this->assertSame('Valencia', $legacyValencia['lead_access_delegation']);
        $this->assertTrue($this->passesDelegationScope($legacyValencia, 'Valencia'));
        $this->assertFalse($this->passesDelegationScope($legacyValencia, 'Madrid General'));
        $commercialMatch = $legacyValencia;
        $commercialMatch['commercial_delegation'] = 'Madrid General';
        $this->assertTrue($this->passesDelegationScope($commercialMatch, 'Madrid General'));

        $this->assertSame('Valencia', $legacyMadrid['lead_delegation']);
        $this->assertSame('Madrid General', $legacyMadrid['lead_access_delegation']);
        $this->assertTrue($this->passesDelegationScope($legacyMadrid, 'Madrid General'));
        $this->assertFalse($this->passesDelegationScope($legacyMadrid, 'Valencia'));
    }

    public function test_owner_solo_es_fallback_de_delegacion_para_tipo_funcional_exposicion(): void
    {
        $exposition = $this->service->decorateLead([
            'status' => 'Potencial',
            'record_type_name' => 'Exposición',
            'portal_text' => 'Web',
            'owner_delegation' => 'HR MOTOR VALENCIA',
        ]);
        $sale = $this->service->decorateLead([
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'portal_text' => 'Web',
            'owner_delegation' => 'HR MOTOR VALENCIA',
        ]);
        $saleWithExpositionPortal = $this->service->decorateLead([
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'portal_text' => 'Exposición',
            'owner_delegation' => 'HR MOTOR VALENCIA',
        ]);
        $expositionWithBueno = $this->service->decorateLead([
            'status' => 'Potencial',
            'record_type_name' => 'Exposición',
            'portal_text' => 'Web',
            'delegacion_encargada_bueno' => 'HR MOTOR ZARAGOZA',
            'owner_delegation' => 'HR MOTOR VALENCIA',
        ]);
        $expositionWithEncargada = $this->service->decorateLead([
            'status' => 'Potencial',
            'record_type_name' => 'Exposición',
            'portal_text' => 'Web',
            'delegacion_encargada' => 'HR MOTOR VALENCIA',
            'owner_delegation' => 'HR MOTOR ZARAGOZA',
        ]);
        $saleWithWorkerDelegation = $this->service->decorateLead([
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'portal_text' => 'Web',
            'persona_que_trabajo_delegation' => 'HR MOTOR VALENCIA',
        ]);

        $this->assertSame('Valencia', $exposition['lead_delegation']);
        $this->assertSame('owner_delegation', $exposition['lead_delegation_effective_source']);
        $this->assertNull($exposition['delegation_resolution']['effective_value']);
        $this->assertSame('Sin clasificar', $sale['lead_delegation']);
        $this->assertSame('Sin clasificar', $saleWithExpositionPortal['lead_delegation']);
        $this->assertSame('Zaragoza', $expositionWithBueno['lead_delegation']);
        $this->assertSame('Valencia', $expositionWithEncargada['lead_delegation']);
        $this->assertSame('Sin clasificar', $saleWithWorkerDelegation['lead_delegation']);
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

        $this->assertSame('Sin clasificar', $converted['commercial_delegation']);
        $this->assertSame('Valencia', $discarded['commercial_delegation']);
        $this->assertSame('Sin clasificar', $potential['commercial_delegation']);
        $this->assertSame('Sin clasificar', $potential['commercial_zone']);
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

    private function passesDelegationScope(array $lead, string $delegation): bool
    {
        $method = new \ReflectionMethod($this->service, 'passesAccessScope');

        return $method->invoke($this->service, $lead, [
            'access_commercial' => null,
            'access_zone' => null,
            'access_delegation' => $delegation,
        ]);
    }
}
