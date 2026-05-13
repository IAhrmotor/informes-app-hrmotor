<?php

namespace Tests\Unit;

use App\Services\Reports\MonthlyCommercial\MonthlyCommercialAggregator;
use PHPUnit\Framework\TestCase;

class MonthlyCommercialAggregatorTest extends TestCase
{
    private MonthlyCommercialAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aggregator = new MonthlyCommercialAggregator();
    }

    public function test_calcula_conversion_sobre_total(): void
    {
        $result = $this->aggregator->aggregate([
            $this->lead(['es_convertido' => true]),
            $this->lead(),
            $this->lead(),
        ]);

        $this->assertSame(0.3333, $result['conversion_sobre_total']);
    }

    public function test_calcula_descarte_sobre_total(): void
    {
        $result = $this->aggregator->aggregate([
            $this->lead(['es_descartado' => true]),
            $this->lead(),
        ]);

        $this->assertSame(0.5, $result['descarte_sobre_total']);
    }

    public function test_cuenta_potencial_sin_seguimiento_mayor_3_dias(): void
    {
        $result = $this->aggregator->aggregate([
            $this->lead([
                'es_potencial' => true,
                'potencial_sin_seguimiento_mayor_3_dias' => true,
            ]),
            $this->lead(['es_potencial' => true]),
        ]);

        $this->assertSame(1, $result['potenciales_sin_seguimiento_mayor_3_dias']);
        $this->assertSame(0.5, $result['ratio_potenciales_sin_seguimiento_mayor_3_dias']);
    }

    public function test_calcula_ratio_primera_gestion_menos_1h_sobre_asignados(): void
    {
        $result = $this->aggregator->aggregate([
            $this->lead([
                'fecha_asignacion' => '2026-05-13T10:00:00+00:00',
                'fecha_primer_contacto' => '2026-05-13T10:30:00+00:00',
                'tiempo_asignacion_primera_actividad_minutos' => 30,
                'tiempo_asignacion_primera_actividad_horas' => 0.5,
            ]),
            $this->lead([
                'fecha_asignacion' => '2026-05-13T10:00:00+00:00',
            ]),
        ]);

        $this->assertSame(0.5, $result['ratio_respondidos_menos_1h_sobre_asignados']);
    }

    private function lead(array $overrides = []): array
    {
        return array_merge([
            'es_potencial' => false,
            'es_convertido' => false,
            'es_descartado' => false,
            'tiene_task_event_registrada' => false,
            'dias_desde_ultima_task_event' => null,
            'total_actividades' => 0,
            'fecha_asignacion' => null,
            'fecha_primer_contacto' => null,
            'tiempo_creacion_asignacion_minutos' => null,
            'tiempo_asignacion_primera_actividad_minutos' => null,
            'tiempo_creacion_asignacion_horas' => null,
            'tiempo_asignacion_primera_actividad_horas' => null,
            'tiempo_creacion_primera_actividad_horas' => null,
        ], $overrides);
    }
}
