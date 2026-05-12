<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Models\LeadNormalized;
use App\Services\Reports\Leads\LeadQualityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadDashboardDataController extends Controller
{
    public function resumen(Request $request): JsonResponse
    {
        if (! $this->hasNormalizedData()) {
            return response()->json([
                'total_leads' => 12996,
                'llamadas' => 5311,
                'formularios' => 7685,
                'convertidos' => 0,
                'conversion_pct' => null,
                'descartados' => 0,
                'descarte_pct' => null,
                'potenciales' => 0,
                'pendientes_clasificar' => 0,
                'nota' => 'La vista sin Exposición permite analizar captación real sin leads recreados manualmente por comerciales.',
            ]);
        }

        $query = $this->baseQuery($request);

        $total = (clone $query)->count();
        $converted = (clone $query)->where('is_converted', true)->count();
        $discarded = (clone $query)->where('is_discarded', true)->count();

        return response()->json([
            'total_leads' => $total,
            'llamadas' => (clone $query)->where('channel_direction', 'Llamada')->count(),
            'formularios' => (clone $query)->where('channel_direction', 'Formulario')->count(),
            'convertidos' => $converted,
            'conversion_pct' => $this->percentage($converted, $total),
            'descartados' => $discarded,
            'descarte_pct' => $this->percentage($discarded, $total),
            'potenciales' => (clone $query)->where('is_potential', true)->count(),
            'pendientes_clasificar' => (clone $query)->where('data_quality_status', '!=', 'ok')->count(),
            'nota' => 'La vista sin Exposición permite analizar captación real sin leads recreados manualmente por comerciales.',
        ]);
    }

    public function kpis(Request $request): JsonResponse
    {
        if (! $this->hasNormalizedData()) {
            return response()->json([
                'items' => [
                    ['metrica' => 'Leads totales', 'con_exposicion' => 12996, 'sin_exposicion' => 12503, 'diferencia' => 493],
                    ['metrica' => 'Llamadas', 'con_exposicion' => 5311, 'sin_exposicion' => 5311, 'diferencia' => 0],
                    ['metrica' => 'Formularios', 'con_exposicion' => 7685, 'sin_exposicion' => 7192, 'diferencia' => 493],
                ],
                'solo_exposicion' => [
                    'leads' => 493,
                    'convertidos' => 0,
                    'conversion_pct' => null,
                    'descartados' => 0,
                    'descarte_pct' => null,
                ],
            ]);
        }

        $with = $this->baseQuery($request);
        $without = $this->baseQuery($request)->where('is_exposition', false);
        $only = $this->baseQuery($request)->where('is_exposition', true);

        $withTotal = (clone $with)->count();
        $withoutTotal = (clone $without)->count();

        $withConverted = (clone $with)->where('is_converted', true)->count();
        $withoutConverted = (clone $without)->where('is_converted', true)->count();

        $withDiscarded = (clone $with)->where('is_discarded', true)->count();
        $withoutDiscarded = (clone $without)->where('is_discarded', true)->count();

        return response()->json([
            'items' => [
                $this->kpiRow('Leads totales', $withTotal, $withoutTotal),
                $this->kpiRow('Convertidos', $withConverted, $withoutConverted),
                $this->kpiRow('% conversión', $this->percentage($withConverted, $withTotal), $this->percentage($withoutConverted, $withoutTotal)),
                $this->kpiRow('Descartados', $withDiscarded, $withoutDiscarded),
                $this->kpiRow('% descarte', $this->percentage($withDiscarded, $withTotal), $this->percentage($withoutDiscarded, $withoutTotal)),
                $this->kpiRow('Potenciales', (clone $with)->where('is_potential', true)->count(), (clone $without)->where('is_potential', true)->count()),
                $this->kpiRow('Potenciales sin Task/Event', (clone $with)->where('is_potential', true)->where('has_task_event', false)->count(), (clone $without)->where('is_potential', true)->where('has_task_event', false)->count()),
                $this->kpiRow('Cobertura de Task/Event', (clone $with)->where('has_task_event', true)->count(), (clone $without)->where('has_task_event', true)->count()),
            ],
            'solo_exposicion' => [
                'leads' => (clone $only)->count(),
                'convertidos' => (clone $only)->where('is_converted', true)->count(),
                'conversion_pct' => $this->percentage((clone $only)->where('is_converted', true)->count(), (clone $only)->count()),
                'descartados' => (clone $only)->where('is_discarded', true)->count(),
                'descarte_pct' => $this->percentage((clone $only)->where('is_discarded', true)->count(), (clone $only)->count()),
            ],
        ]);
    }

    public function portales(Request $request): JsonResponse
    {
        if (! $this->hasNormalizedData()) {
            return response()->json([
                'items' => [
                    ['portal' => 'Web', 'llamadas' => 1192, 'formularios' => 3368, 'total' => 4560, 'convertidos' => 0, 'conversion_pct' => null],
                    ['portal' => 'Google Maps', 'llamadas' => 3089, 'formularios' => 0, 'total' => 3089, 'convertidos' => 0, 'conversion_pct' => null],
                    ['portal' => 'Meta', 'llamadas' => 0, 'formularios' => 1896, 'total' => 1896, 'convertidos' => 0, 'conversion_pct' => null],
                    ['portal' => 'Coches.net', 'llamadas' => 655, 'formularios' => 808, 'total' => 1463, 'convertidos' => 0, 'conversion_pct' => null],
                    ['portal' => 'Wallapop', 'llamadas' => 196, 'formularios' => 522, 'total' => 718, 'convertidos' => 0, 'conversion_pct' => null],
                    ['portal' => 'Exposición', 'llamadas' => 0, 'formularios' => 493, 'total' => 493, 'convertidos' => 0, 'conversion_pct' => null],
                ],
            ]);
        }

        $query = $this->baseQuery($request);

        $items = (clone $query)
            ->selectRaw("
                portal_original as portal,
                SUM(CASE WHEN channel_direction = 'Llamada' THEN 1 ELSE 0 END) as llamadas,
                SUM(CASE WHEN channel_direction = 'Formulario' THEN 1 ELSE 0 END) as formularios,
                COUNT(*) as total,
                SUM(CASE WHEN is_converted = 1 THEN 1 ELSE 0 END) as convertidos
            ")
            ->groupBy('portal_original')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                return [
                    'portal' => $row->portal ?? 'Sin portal',
                    'llamadas' => (int) $row->llamadas,
                    'formularios' => (int) $row->formularios,
                    'total' => (int) $row->total,
                    'convertidos' => (int) $row->convertidos,
                    'conversion_pct' => $this->percentage((int) $row->convertidos, (int) $row->total),
                ];
            });

        return response()->json(['items' => $items]);
    }

    public function portalDetalle(Request $request): JsonResponse
    {
        $portal = $request->string('portal')->toString() ?: 'Web';

        if (! $this->hasNormalizedData()) {
            return response()->json([
                'portal' => $portal,
                'items' => [
                    ['delegacion' => 'Sant Boi de Llobregat', 'tipo' => 'Delegación', 'grupo_comercial' => 'Barcelona', 'llamadas' => 108, 'formularios' => 184, 'total' => 292],
                    ['delegacion' => 'Rivas-Vaciamadrid', 'tipo' => 'Delegación', 'grupo_comercial' => 'Madrid', 'llamadas' => 100, 'formularios' => 187, 'total' => 287],
                    ['delegacion' => 'Alicante', 'tipo' => 'Delegación', 'grupo_comercial' => 'Alicante', 'llamadas' => 59, 'formularios' => 184, 'total' => 243],
                    ['delegacion' => 'Pendiente de clasificar', 'tipo' => 'Pendiente', 'grupo_comercial' => null, 'llamadas' => 0, 'formularios' => 339, 'total' => 339],
                ],
            ]);
        }

        $query = $this->baseQuery($request)
            ->where('portal_original', $portal);

        $items = $query
            ->selectRaw("
                COALESCE(delegation_name, commercial_group, 'Pendiente de clasificar') as delegacion,
                CASE
                    WHEN delegation_name IS NOT NULL THEN 'Delegación'
                    WHEN commercial_group IS NOT NULL THEN 'Grupo'
                    ELSE 'Pendiente'
                END as tipo,
                commercial_group as grupo_comercial,
                SUM(CASE WHEN channel_direction = 'Llamada' THEN 1 ELSE 0 END) as llamadas,
                SUM(CASE WHEN channel_direction = 'Formulario' THEN 1 ELSE 0 END) as formularios,
                COUNT(*) as total
            ")
            ->groupBy('delegacion', 'tipo', 'grupo_comercial')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'delegacion' => $row->delegacion,
                'tipo' => $row->tipo,
                'grupo_comercial' => $row->grupo_comercial,
                'llamadas' => (int) $row->llamadas,
                'formularios' => (int) $row->formularios,
                'total' => (int) $row->total,
            ]);

        return response()->json([
            'portal' => $portal,
            'items' => $items,
        ]);
    }

    public function delegaciones(Request $request): JsonResponse
    {
        if (! $this->hasNormalizedData()) {
            return response()->json(['items' => []]);
        }

        $query = $this->baseQuery($request);

        $items = (clone $query)
            ->selectRaw("
                COALESCE(delegation_name, commercial_group, 'Pendiente de clasificar') as delegacion,
                CASE
                    WHEN delegation_name IS NOT NULL THEN 'Delegación'
                    WHEN commercial_group IS NOT NULL THEN 'Grupo'
                    ELSE 'Pendiente'
                END as tipo,
                commercial_group as grupo_comercial,
                SUM(CASE WHEN channel_direction = 'Llamada' THEN 1 ELSE 0 END) as llamadas,
                SUM(CASE WHEN channel_direction = 'Formulario' THEN 1 ELSE 0 END) as formularios,
                COUNT(*) as total,
                SUM(CASE WHEN is_converted = 1 THEN 1 ELSE 0 END) as convertidos,
                SUM(CASE WHEN is_discarded = 1 THEN 1 ELSE 0 END) as descartados,
                SUM(CASE WHEN is_potential = 1 THEN 1 ELSE 0 END) as potenciales,
                SUM(CASE WHEN data_quality_status != 'ok' THEN 1 ELSE 0 END) as incidencias
            ")
            ->groupBy('delegacion', 'tipo', 'grupo_comercial')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'delegacion' => $row->delegacion,
                'tipo' => $row->tipo,
                'grupo_comercial' => $row->grupo_comercial,
                'llamadas' => (int) $row->llamadas,
                'formularios' => (int) $row->formularios,
                'total' => (int) $row->total,
                'convertidos' => (int) $row->convertidos,
                'conversion_pct' => $this->percentage((int) $row->convertidos, (int) $row->total),
                'descartados' => (int) $row->descartados,
                'descarte_pct' => $this->percentage((int) $row->descartados, (int) $row->total),
                'potenciales' => (int) $row->potenciales,
                'incidencias' => (int) $row->incidencias,
            ])
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function comerciales(Request $request): JsonResponse
    {
        if (! $this->hasNormalizedData()) {
            return response()->json(['items' => []]);
        }

        $query = $this->baseQuery($request);

        $items = (clone $query)
            ->selectRaw("
                COALESCE(commercial_name, 'Sin comercial') as comercial,
                SUM(CASE WHEN channel_direction = 'Llamada' THEN 1 ELSE 0 END) as llamadas,
                SUM(CASE WHEN channel_direction = 'Formulario' THEN 1 ELSE 0 END) as formularios,
                COUNT(*) as total,
                SUM(CASE WHEN is_converted = 1 THEN 1 ELSE 0 END) as convertidos,
                SUM(CASE WHEN is_discarded = 1 THEN 1 ELSE 0 END) as descartados,
                SUM(CASE WHEN is_potential = 1 THEN 1 ELSE 0 END) as potenciales,
                SUM(CASE WHEN has_task_event = 1 THEN 1 ELSE 0 END) as con_task_event,
                SUM(CASE WHEN has_task_event = 0 THEN 1 ELSE 0 END) as sin_task_event,
                SUM(CASE WHEN has_recent_follow_up = 0 THEN 1 ELSE 0 END) as sin_seguimiento_reciente,
                AVG(minutes_to_assignment) as tiempo_medio_asignacion,
                AVG(minutes_to_first_task_event) as tiempo_medio_primera_actividad
            ")
            ->groupBy('comercial')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'comercial' => $row->comercial,
                'llamadas' => (int) $row->llamadas,
                'formularios' => (int) $row->formularios,
                'total' => (int) $row->total,
                'convertidos' => (int) $row->convertidos,
                'conversion_pct' => $this->percentage((int) $row->convertidos, (int) $row->total),
                'descartados' => (int) $row->descartados,
                'descarte_pct' => $this->percentage((int) $row->descartados, (int) $row->total),
                'potenciales' => (int) $row->potenciales,
                'con_task_event' => (int) $row->con_task_event,
                'sin_task_event' => (int) $row->sin_task_event,
                'cobertura_task_event_pct' => $this->percentage((int) $row->con_task_event, (int) $row->total),
                'sin_seguimiento_reciente' => (int) $row->sin_seguimiento_reciente,
                'tiempo_medio_asignacion' => $row->tiempo_medio_asignacion !== null ? round((float) $row->tiempo_medio_asignacion, 1) : null,
                'tiempo_medio_primera_actividad' => $row->tiempo_medio_primera_actividad !== null ? round((float) $row->tiempo_medio_primera_actividad, 1) : null,
            ])
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function comparativa(Request $request): JsonResponse
    {
        if (! $this->hasNormalizedData()) {
            return response()->json(['items' => []]);
        }

        /*
        * Primera versión funcional:
        * - Periodo actual: últimos 30 días.
        * - Periodo comparado: 30 días anteriores.
        *
        * Más adelante conectaremos estos rangos a filtros de fecha personalizados.
        */
        $currentFrom = now()->subDays(30)->startOfDay();
        $currentTo = now()->endOfDay();

        $previousFrom = now()->subDays(60)->startOfDay();
        $previousTo = now()->subDays(30)->endOfDay();

        $currentQuery = $this->baseQuery($request)
            ->whereBetween('lead_created_at', [$currentFrom, $currentTo]);

        $previousQuery = $this->baseQuery($request)
            ->whereBetween('lead_created_at', [$previousFrom, $previousTo]);

        $current = $this->summaryMetrics($currentQuery);
        $previous = $this->summaryMetrics($previousQuery);

        $items = [
            $this->comparisonRow('Leads', $current['total'], $previous['total']),
            $this->comparisonRow('Llamadas', $current['llamadas'], $previous['llamadas']),
            $this->comparisonRow('Formularios', $current['formularios'], $previous['formularios']),
            $this->comparisonRow('Convertidos', $current['convertidos'], $previous['convertidos']),
            $this->comparisonRow('% conversión', $current['conversion_pct'], $previous['conversion_pct'], true),
            $this->comparisonRow('Descartados', $current['descartados'], $previous['descartados']),
            $this->comparisonRow('% descarte', $current['descarte_pct'], $previous['descarte_pct'], true),
            $this->comparisonRow('Potenciales', $current['potenciales'], $previous['potenciales']),
            $this->comparisonRow('Potenciales sin Task/Event', $current['potenciales_sin_task_event'], $previous['potenciales_sin_task_event']),
            $this->comparisonRow('Cobertura Task/Event', $current['cobertura_task_event_pct'], $previous['cobertura_task_event_pct'], true),
            $this->comparisonRow('Tiempo medio hasta asignación', $current['tiempo_medio_asignacion'], $previous['tiempo_medio_asignacion']),
            $this->comparisonRow('Tiempo medio hasta primera Task/Event', $current['tiempo_medio_primera_actividad'], $previous['tiempo_medio_primera_actividad']),
        ];

        return response()->json([
            'periodo_actual' => [
                'desde' => $currentFrom->toDateString(),
                'hasta' => $currentTo->toDateString(),
            ],
            'periodo_comparado' => [
                'desde' => $previousFrom->toDateString(),
                'hasta' => $previousTo->toDateString(),
            ],
            'items' => $items,
        ]);
    }

    public function calidadDato(Request $request, LeadQualityService $quality): JsonResponse
    {
        if (! $this->hasNormalizedData()) {
            return response()->json([
                'items' => [
                    ['incidencia' => 'Formularios sin Remitente Lead', 'registros' => 0, 'accion' => 'Revisar campo alternativo'],
                    ['incidencia' => 'Remitente Lead no mapeado', 'registros' => 0, 'accion' => 'Añadir a tabla maestra'],
                    ['incidencia' => 'Llamadas sin delegación', 'registros' => 0, 'accion' => 'Revisar centralita'],
                    ['incidencia' => 'Portal sin grupo portal', 'registros' => 0, 'accion' => 'Añadir a tabla maestra'],
                    ['incidencia' => 'Delegación no reconocida', 'registros' => 0, 'accion' => 'Normalizar'],
                    ['incidencia' => 'Exposición sin propietario/delegación trabajador', 'registros' => 0, 'accion' => 'Completar criterio'],
                ],
            ]);
        }

        return response()->json([
            'items' => $quality->summary(),
        ]);
    }

    private function baseQuery(Request $request): Builder
    {
        return LeadNormalized::query()
            ->when($request->filled('channel'), fn ($query) => $query->where('channel_direction', $request->string('channel')->toString()))
            ->when($request->filled('portal'), fn ($query) => $query->where('portal_original', $request->string('portal')->toString()))
            ->when($request->filled('exposition_mode'), function ($query) use ($request) {
                match ($request->string('exposition_mode')->toString()) {
                    'without' => $query->where('is_exposition', false),
                    'only' => $query->where('is_exposition', true),
                    default => null,
                };
            });
    }

    private function hasNormalizedData(): bool
    {
        return LeadNormalized::query()->exists();
    }

    private function percentage(int|float|null $value, int|float|null $total): ?float
    {
        if (! $total) {
            return null;
        }

        return round(($value / $total) * 100, 2);
    }

    private function kpiRow(string $name, int|float|null $with, int|float|null $without): array
    {
        return [
            'metrica' => $name,
            'con_exposicion' => $with,
            'sin_exposicion' => $without,
            'diferencia' => $with !== null && $without !== null ? round($with - $without, 2) : null,
        ];
    }

    private function summaryMetrics(Builder $query): array
    {
        $total = (clone $query)->count();

        $llamadas = (clone $query)->where('channel_direction', 'Llamada')->count();
        $formularios = (clone $query)->where('channel_direction', 'Formulario')->count();

        $convertidos = (clone $query)->where('is_converted', true)->count();
        $descartados = (clone $query)->where('is_discarded', true)->count();
        $potenciales = (clone $query)->where('is_potential', true)->count();

        $potencialesSinTaskEvent = (clone $query)
            ->where('is_potential', true)
            ->where('has_task_event', false)
            ->count();

        $conTaskEvent = (clone $query)->where('has_task_event', true)->count();

        $tiempoMedioAsignacion = (clone $query)->avg('minutes_to_assignment');
        $tiempoMedioPrimeraActividad = (clone $query)->avg('minutes_to_first_task_event');

        return [
            'total' => $total,
            'llamadas' => $llamadas,
            'formularios' => $formularios,
            'convertidos' => $convertidos,
            'conversion_pct' => $this->percentage($convertidos, $total),
            'descartados' => $descartados,
            'descarte_pct' => $this->percentage($descartados, $total),
            'potenciales' => $potenciales,
            'potenciales_sin_task_event' => $potencialesSinTaskEvent,
            'cobertura_task_event_pct' => $this->percentage($conTaskEvent, $total),
            'tiempo_medio_asignacion' => $tiempoMedioAsignacion !== null ? round((float) $tiempoMedioAsignacion, 1) : null,
            'tiempo_medio_primera_actividad' => $tiempoMedioPrimeraActividad !== null ? round((float) $tiempoMedioPrimeraActividad, 1) : null,
        ];
    }

    private function comparisonRow(string $metric, int|float|null $current, int|float|null $previous, bool $isPercentage = false): array
    {
        $diff = null;

        if ($current !== null && $previous !== null) {
            $diff = round($current - $previous, 2);
        }

        return [
            'metrica' => $metric,
            'periodo_actual' => $current,
            'periodo_comparado' => $previous,
            'diferencia' => $diff,
            'is_percentage' => $isPercentage,
        ];
    }
}