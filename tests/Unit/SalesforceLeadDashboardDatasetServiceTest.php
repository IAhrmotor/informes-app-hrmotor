<?php

namespace Tests\Unit;

use App\Services\Reports\Leads\SalesforceLeadDashboardDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceLeadDashboardDatasetServiceTest extends TestCase
{
    use RefreshDatabase;

    private SalesforceLeadDashboardDatasetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SalesforceLeadDashboardDatasetService::class);
    }

    public function test_resuelve_canal_llamada_si_medio_nuevo_es_llamada(): void
    {
        $lead = $this->service->decorateLead(['status' => 'Potencial', 'medio_nuevo' => 'Llamada']);

        $this->assertSame('Llamada', $lead['canal']);
        $this->assertTrue($lead['is_llamada']);
    }

    public function test_resuelve_canal_formulario_si_medio_nuevo_no_es_llamada(): void
    {
        $lead = $this->service->decorateLead(['status' => 'Potencial', 'medio_nuevo' => 'Email']);

        $this->assertSame('Formulario', $lead['canal']);
        $this->assertTrue($lead['is_formulario']);
    }

    public function test_portal_de_llamada_usa_fuente_nuevo(): void
    {
        $lead = $this->service->decorateLead([
            'status' => 'Potencial',
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Google Maps',
            'portal_text' => 'Web',
        ]);

        $this->assertSame('Google Maps', $lead['portal']);
    }

    public function test_portal_de_formulario_usa_portal_text_y_fallback_fuente_origen(): void
    {
        $lead = $this->service->decorateLead([
            'status' => 'Potencial',
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
            'fuente_origen' => 'Meta',
        ]);

        $fallback = $this->service->decorateLead([
            'status' => 'Potencial',
            'medio_nuevo' => 'Formulario',
            'fuente_origen' => 'Meta',
        ]);

        $this->assertSame('Web', $lead['portal']);
        $this->assertSame('Meta', $fallback['portal']);
    }

    public function test_potencial_sin_trabajar_si_no_tiene_actividad_o_si_ultima_es_mayor_3_dias(): void
    {
        $now = CarbonImmutable::parse('2026-05-13 12:00:00');

        $withoutActivity = $this->service->decorateLead(['status' => 'Potencial'], ['total_actividades' => 0], $now);
        $oldActivity = $this->service->decorateLead(['status' => 'Potencial'], [
            'total_actividades' => 1,
            'fecha_ultima_actividad' => '2026-05-01 12:00:00',
        ], $now);

        $this->assertTrue($withoutActivity['is_potencial_sin_trabajar']);
        $this->assertTrue($oldActivity['is_potencial_sin_trabajar']);
    }

    public function test_convertido_y_descartado_no_cuentan_como_sin_trabajar_sin_actividad(): void
    {
        $converted = $this->service->decorateLead(['status' => 'Convertido'], ['total_actividades' => 0]);
        $discarded = $this->service->decorateLead(['status' => 'Descartado'], ['total_actividades' => 0]);

        $this->assertFalse($converted['is_potencial_sin_trabajar']);
        $this->assertFalse($discarded['is_potencial_sin_trabajar']);
    }

    public function test_gestionado_si_convertido_descartado_o_potencial_con_actividad_reciente(): void
    {
        $now = CarbonImmutable::parse('2026-05-13 12:00:00');

        $converted = $this->service->decorateLead(['status' => 'Convertido'], [], $now);
        $discarded = $this->service->decorateLead(['status' => 'Descartado'], [], $now);
        $potential = $this->service->decorateLead(['status' => 'Potencial'], [
            'total_actividades' => 1,
            'fecha_ultima_actividad' => '2026-05-12 12:00:00',
        ], $now);

        $this->assertTrue($converted['is_gestionado']);
        $this->assertTrue($discarded['is_gestionado']);
        $this->assertTrue($potential['is_gestionado']);
    }
}
