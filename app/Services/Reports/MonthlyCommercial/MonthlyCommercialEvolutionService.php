<?php

namespace App\Services\Reports\MonthlyCommercial;

class MonthlyCommercialEvolutionService
{
    public function compare(array $current, array $previous): array
    {
        $metrics = [
            ['key' => 'leads_totales', 'label' => 'Leads', 'ratio' => false],
            ['key' => 'leads_convertidos', 'label' => 'Convertidos', 'ratio' => false],
            ['key' => 'conversion_sobre_total', 'label' => 'Conversion sobre total', 'ratio' => true],
            ['key' => 'leads_descartados', 'label' => 'Descartados', 'ratio' => false],
            ['key' => 'descarte_sobre_total', 'label' => 'Descarte sobre total', 'ratio' => true],
            ['key' => 'leads_potenciales', 'label' => 'Potenciales', 'ratio' => false],
            ['key' => 'potenciales_sin_seguimiento_mayor_3_dias', 'label' => 'Potenciales sin seguimiento >3 dias', 'ratio' => false],
            ['key' => 'ratio_con_primera_actividad_sobre_asignados', 'label' => 'Con primera Task/Event sobre asignados', 'ratio' => true],
            ['key' => 'ratio_respondidos_menos_1h_sobre_asignados', 'label' => 'Primera gestion <1h sobre asignados', 'ratio' => true],
            ['key' => 'tiempo_medio_respuesta_horas', 'label' => 'Tiempo medio primera Task/Event', 'ratio' => false],
        ];

        $items = [];

        foreach ($metrics as $metric) {
            $currentValue = $current[$metric['key']] ?? null;
            $previousValue = $previous[$metric['key']] ?? null;

            $items[] = [
                'key' => $metric['key'],
                'metrica' => $metric['label'],
                'periodo_actual' => $currentValue,
                'periodo_anterior' => $previousValue,
                'diferencia' => $this->diff($currentValue, $previousValue),
                'variacion_relativa' => $this->relativeDiff($currentValue, $previousValue),
                'is_ratio' => $metric['ratio'],
            ];
        }

        return ['items' => $items];
    }

    private function diff(mixed $current, mixed $previous): ?float
    {
        if (! is_numeric($current) || ! is_numeric($previous)) {
            return null;
        }

        return round((float) $current - (float) $previous, 4);
    }

    private function relativeDiff(mixed $current, mixed $previous): ?float
    {
        if (! is_numeric($current) || ! is_numeric($previous) || (float) $previous === 0.0) {
            return null;
        }

        return round(((float) $current - (float) $previous) / (float) $previous, 4);
    }
}
