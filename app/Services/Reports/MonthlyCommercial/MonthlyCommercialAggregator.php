<?php

namespace App\Services\Reports\MonthlyCommercial;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class MonthlyCommercialAggregator
{
    public function aggregate(iterable $leads): array
    {
        $bucket = $this->emptyBucket();

        foreach ($leads as $lead) {
            $this->addLead($bucket, (array) $lead);
        }

        return $this->finalize($bucket);
    }

    public function groupBy(iterable $leads, string $field, string $labelKey): array
    {
        $groups = [];

        foreach ($leads as $lead) {
            $lead = (array) $lead;
            $label = data_get($lead, $field) ?: 'Sin dato';

            $groups[$label] ??= [];
            $groups[$label][] = $lead;
        }

        $rows = [];

        foreach ($groups as $label => $items) {
            $rows[] = array_merge([$labelKey => $label], $this->aggregate($items));
        }

        usort($rows, fn (array $a, array $b) => ($b['leads_totales'] ?? 0) <=> ($a['leads_totales'] ?? 0));

        return array_values($rows);
    }

    public function filter(iterable $leads, callable $callback): array
    {
        $filtered = [];

        foreach ($leads as $lead) {
            if ($callback((array) $lead)) {
                $filtered[] = (array) $lead;
            }
        }

        return $filtered;
    }

    public function sortRows(array $rows, string $metric, int $limit = 0): array
    {
        usort($rows, fn (array $a, array $b) => ($b[$metric] ?? 0) <=> ($a[$metric] ?? 0));

        if ($limit > 0) {
            return array_slice($rows, 0, $limit);
        }

        return $rows;
    }

    public function average(array $values): ?float
    {
        $values = $this->numericValues($values);

        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    public function median(array $values): ?float
    {
        $values = $this->numericValues($values);

        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return round($values[$middle], 2);
        }

        return round(($values[$middle - 1] + $values[$middle]) / 2, 2);
    }

    public function percentile(array $values, float $percentile): ?float
    {
        $values = $this->numericValues($values);

        if ($values === []) {
            return null;
        }

        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return round($values[$lower], 2);
        }

        $weight = $index - $lower;

        return round($values[$lower] * (1 - $weight) + $values[$upper] * $weight, 2);
    }

    public function roundRatio(int|float|null $value, int|float|null $total): ?float
    {
        if (! $total) {
            return null;
        }

        return round(((float) $value) / ((float) $total), 4);
    }

    public function diffMinutes(mixed $from, mixed $to): ?int
    {
        if (blank($from) || blank($to)) {
            return null;
        }

        try {
            return (int) abs(CarbonImmutable::parse($from)->diffInMinutes(CarbonImmutable::parse($to)));
        } catch (\Throwable) {
            return null;
        }
    }

    public function diffDaysFromNow(mixed $date, ?CarbonInterface $now = null): ?int
    {
        if (blank($date)) {
            return null;
        }

        try {
            return (int) floor(CarbonImmutable::parse($date)->diffInDays($now ? CarbonImmutable::parse($now) : CarbonImmutable::now()));
        } catch (\Throwable) {
            return null;
        }
    }

    private function addLead(array &$bucket, array $lead): void
    {
        $bucket['leads_totales']++;

        $isPotential = (bool) ($lead['es_potencial'] ?? false);
        $isConverted = (bool) ($lead['es_convertido'] ?? false);
        $isDiscarded = (bool) ($lead['es_descartado'] ?? false);
        $hasActivity = (bool) ($lead['tiene_task_event_registrada'] ?? false);
        $lastActivityDays = $lead['dias_desde_ultima_task_event'] ?? null;
        $hasRecentActivity = $hasActivity && $lastActivityDays !== null && $lastActivityDays <= 3;

        $bucket['leads_potenciales'] += $isPotential ? 1 : 0;
        $bucket['leads_convertidos'] += $isConverted ? 1 : 0;
        $bucket['leads_descartados'] += $isDiscarded ? 1 : 0;

        // En este informe "gestionado" incluye trazabilidad Task/Event o un estado final.
        $bucket['leads_gestionados'] += ($hasActivity || $isConverted || $isDiscarded) ? 1 : 0;
        // "Trabajado" se usa como denominador de conversion/descarte: convertido + descartado.
        $bucket['leads_trabajados'] += ($isConverted || $isDiscarded) ? 1 : 0;

        $bucket['leads_con_actividad_reciente'] += $hasRecentActivity ? 1 : 0;
        $bucket['leads_sin_actividad_reciente'] += $hasRecentActivity ? 0 : 1;
        $bucket['potenciales_sin_ninguna_task_event'] += (bool) ($lead['potencial_sin_ninguna_task_event'] ?? false) ? 1 : 0;
        $bucket['potenciales_con_ultima_task_mayor_3_dias'] += (bool) ($lead['potencial_con_ultima_task_mayor_3_dias'] ?? false) ? 1 : 0;
        $bucket['potenciales_sin_seguimiento_mayor_3_dias'] += (bool) ($lead['potencial_sin_seguimiento_mayor_3_dias'] ?? false) ? 1 : 0;
        $bucket['actividades_recientes_total'] += $hasRecentActivity ? (int) ($lead['total_actividades'] ?? 0) : 0;

        if ($lastActivityDays !== null) {
            $bucket['total_dias_desde_ultima_task_event'] += (int) $lastActivityDays;
            $bucket['cuenta_dias_desde_ultima_task_event']++;
        }

        $this->pushNumber($bucket['tiempos_creacion_asignacion_horas'], $lead['tiempo_creacion_asignacion_horas'] ?? null);
        $this->pushNumber($bucket['tiempos_asignacion_primera_actividad_horas'], $lead['tiempo_asignacion_primera_actividad_horas'] ?? null);
        $this->pushNumber($bucket['tiempos_creacion_primera_actividad_horas'], $lead['tiempo_creacion_primera_actividad_horas'] ?? null);

        $hasAssignment = filled($lead['fecha_asignacion'] ?? null);
        $hasFirstActivity = filled($lead['fecha_primer_contacto'] ?? null);
        $assignmentMinutes = $lead['tiempo_creacion_asignacion_minutos'] ?? null;
        $responseMinutes = $lead['tiempo_asignacion_primera_actividad_minutos'] ?? null;

        if ($hasAssignment) {
            $bucket['leads_con_fecha_asignacion']++;
            $bucket['leads_asignados']++;
        }

        if ($hasAssignment && $assignmentMinutes !== null) {
            $bucket['leads_asignados_menos_15_min'] += $assignmentMinutes <= 15 ? 1 : 0;
            $bucket['leads_asignados_menos_30_min'] += $assignmentMinutes <= 30 ? 1 : 0;
            $bucket['leads_asignados_menos_60_min'] += $assignmentMinutes <= 60 ? 1 : 0;
        }

        if ($hasAssignment && $hasFirstActivity) {
            $bucket['leads_con_primera_actividad']++;
        }

        if ($hasAssignment && $hasFirstActivity && $responseMinutes !== null) {
            $bucket['leads_respondidos_menos_1h'] += $responseMinutes <= 60 ? 1 : 0;
            $bucket['leads_respondidos_menos_2h'] += $responseMinutes <= 120 ? 1 : 0;
            $bucket['leads_respondidos_menos_4h'] += $responseMinutes <= 240 ? 1 : 0;
            $bucket['leads_respondidos_menos_24h'] += $responseMinutes <= 1440 ? 1 : 0;
        }

        $bucket['gestor_distinto_owner'] += (bool) ($lead['gestor_distinto_owner'] ?? false) ? 1 : 0;
        $bucket['trabajado_distinto_owner'] += (bool) ($lead['trabajado_distinto_owner'] ?? false) ? 1 : 0;
        $bucket['descarte_distinto_owner'] += (bool) ($lead['descarte_distinto_owner'] ?? false) ? 1 : 0;
        $bucket['primera_actividad_antes_asignacion'] += (bool) ($lead['primera_actividad_antes_asignacion'] ?? false) ? 1 : 0;
    }

    private function finalize(array $bucket): array
    {
        $bucket['conversion_sobre_total'] = $this->roundRatio($bucket['leads_convertidos'], $bucket['leads_totales']);
        $bucket['conversion_sobre_trabajados'] = $this->roundRatio($bucket['leads_convertidos'], $bucket['leads_trabajados']);
        $bucket['descarte_sobre_total'] = $this->roundRatio($bucket['leads_descartados'], $bucket['leads_totales']);
        $bucket['descarte_sobre_trabajados'] = $this->roundRatio($bucket['leads_descartados'], $bucket['leads_trabajados']);
        $bucket['ratio_gestionados_sobre_total'] = $this->roundRatio($bucket['leads_gestionados'], $bucket['leads_totales']);
        $bucket['ratio_trabajados_sobre_total'] = $this->roundRatio($bucket['leads_trabajados'], $bucket['leads_totales']);
        $bucket['ratio_actividad_reciente_sobre_total'] = $this->roundRatio($bucket['leads_con_actividad_reciente'], $bucket['leads_totales']);
        $bucket['ratio_potenciales_sin_seguimiento_mayor_3_dias'] = $this->roundRatio($bucket['potenciales_sin_seguimiento_mayor_3_dias'], $bucket['leads_potenciales']);
        $bucket['dias_medios_desde_ultima_task_event'] = $this->roundRatio($bucket['total_dias_desde_ultima_task_event'], $bucket['cuenta_dias_desde_ultima_task_event']);
        $bucket['tiempo_medio_asignacion_horas'] = $this->average($bucket['tiempos_creacion_asignacion_horas']);
        $bucket['tiempo_mediano_asignacion_horas'] = $this->median($bucket['tiempos_creacion_asignacion_horas']);
        $bucket['tiempo_p90_asignacion_horas'] = $this->percentile($bucket['tiempos_creacion_asignacion_horas'], 90);
        $bucket['tiempo_medio_respuesta_horas'] = $this->average($bucket['tiempos_asignacion_primera_actividad_horas']);
        $bucket['tiempo_mediano_respuesta_horas'] = $this->median($bucket['tiempos_asignacion_primera_actividad_horas']);
        $bucket['tiempo_p90_respuesta_horas'] = $this->percentile($bucket['tiempos_asignacion_primera_actividad_horas'], 90);
        $bucket['ratio_respondidos_menos_1h_sobre_actividad'] = $this->roundRatio($bucket['leads_respondidos_menos_1h'], $bucket['leads_con_primera_actividad']);
        $bucket['ratio_respondidos_menos_1h_sobre_asignados'] = $this->roundRatio($bucket['leads_respondidos_menos_1h'], $bucket['leads_asignados']);
        $bucket['ratio_con_primera_actividad_sobre_asignados'] = $this->roundRatio($bucket['leads_con_primera_actividad'], $bucket['leads_asignados']);
        $bucket['leads_sin_primera_actividad'] = max($bucket['leads_asignados'] - $bucket['leads_con_primera_actividad'], 0);
        $bucket['ratio_gestor_distinto_owner'] = $this->roundRatio($bucket['gestor_distinto_owner'], $bucket['leads_totales']);

        return $bucket;
    }

    private function emptyBucket(): array
    {
        return [
            'leads_totales' => 0,
            'leads_potenciales' => 0,
            'leads_convertidos' => 0,
            'leads_descartados' => 0,
            'leads_gestionados' => 0,
            'leads_trabajados' => 0,
            'leads_con_actividad_reciente' => 0,
            'leads_sin_actividad_reciente' => 0,
            'potenciales_sin_ninguna_task_event' => 0,
            'potenciales_con_ultima_task_mayor_3_dias' => 0,
            'potenciales_sin_seguimiento_mayor_3_dias' => 0,
            'actividades_recientes_total' => 0,
            'total_dias_desde_ultima_task_event' => 0,
            'cuenta_dias_desde_ultima_task_event' => 0,
            'tiempos_creacion_asignacion_horas' => [],
            'tiempos_asignacion_primera_actividad_horas' => [],
            'tiempos_creacion_primera_actividad_horas' => [],
            'leads_con_fecha_asignacion' => 0,
            'leads_asignados_menos_15_min' => 0,
            'leads_asignados_menos_30_min' => 0,
            'leads_asignados_menos_60_min' => 0,
            'leads_respondidos_menos_1h' => 0,
            'leads_respondidos_menos_2h' => 0,
            'leads_respondidos_menos_4h' => 0,
            'leads_respondidos_menos_24h' => 0,
            'gestor_distinto_owner' => 0,
            'trabajado_distinto_owner' => 0,
            'descarte_distinto_owner' => 0,
            'primera_actividad_antes_asignacion' => 0,
            'leads_asignados' => 0,
            'leads_con_primera_actividad' => 0,
            'leads_sin_primera_actividad' => 0,
        ];
    }

    private function pushNumber(array &$values, mixed $value): void
    {
        if (is_numeric($value)) {
            $values[] = (float) $value;
        }
    }

    private function numericValues(array $values): array
    {
        return array_values(array_map('floatval', array_filter($values, 'is_numeric')));
    }
}
