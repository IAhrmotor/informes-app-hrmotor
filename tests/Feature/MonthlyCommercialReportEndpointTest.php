<?php

namespace Tests\Feature;

use App\Models\MonthlyCommercialReportSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCommercialReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_devuelve_ok_false_si_no_hay_snapshot(): void
    {
        $this->getJson('/informes/leads/data/monthly-commercial/summary')
            ->assertOk()
            ->assertJson([
                'ok' => false,
                'message' => 'No hay informe mensual generado todavia.',
            ]);
    }

    public function test_endpoint_summary_devuelve_ok_true_si_hay_snapshot(): void
    {
        MonthlyCommercialReportSnapshot::create([
            'period_start' => CarbonImmutable::parse('2026-04-13 00:00:00'),
            'period_end' => CarbonImmutable::parse('2026-05-13 00:00:00'),
            'previous_period_start' => CarbonImmutable::parse('2026-03-14 00:00:00'),
            'previous_period_end' => CarbonImmutable::parse('2026-04-13 00:00:00'),
            'generated_at' => CarbonImmutable::parse('2026-05-13 12:00:00'),
            'payload_json' => [
                'periodos_estandar' => [],
                'resumen_global' => ['leads_totales' => 10],
                'resumen_ejecutivo' => ['prioridades' => []],
            ],
        ]);

        $this->getJson('/informes/leads/data/monthly-commercial/summary')
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('data.resumen_global.leads_totales', 10);
    }
}
