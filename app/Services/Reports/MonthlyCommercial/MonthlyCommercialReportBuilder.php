<?php

namespace App\Services\Reports\MonthlyCommercial;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MonthlyCommercialReportBuilder
{
    public function __construct(
        private readonly MonthlyCommercialPeriodService $periodService,
        private readonly MonthlyCommercialLeadEnricher $enricher,
        private readonly MonthlyCommercialAggregator $aggregator,
        private readonly MonthlyCommercialEvolutionService $evolutionService,
    ) {
    }

    public function build(int $days = 30, ?CarbonInterface $now = null): array
    {
        $periods = $this->periodService->periods($days, $now);
        $commercialUsers = $this->commercialUsersById();

        $currentLeads = $this->loadLeads($periods['current_start'], $periods['current_end']);
        $previousLeads = $this->loadLeads($periods['previous_start'], $periods['previous_end']);

        $current = $this->enrichLeads($currentLeads, $commercialUsers, $periods['current_end']);
        $previous = $this->enrichLeads($previousLeads, $commercialUsers, $periods['previous_end']);

        $global = $this->aggregator->aggregate($current);
        $previousGlobal = $this->aggregator->aggregate($previous);
        $potentialLeads = $this->aggregator->filter($current, fn (array $lead) => (bool) ($lead['es_potencial'] ?? false));

        $pendingCommercials = $this->aggregator->sortRows(
            $this->aggregator->groupBy($potentialLeads, 'responsable_nombre', 'comercial'),
            'potenciales_sin_seguimiento_mayor_3_dias'
        );
        $pendingDelegations = $this->aggregator->sortRows(
            $this->aggregator->groupBy($potentialLeads, 'delegacion_nombre', 'delegacion'),
            'potenciales_sin_seguimiento_mayor_3_dias'
        );
        $pendingSources = $this->aggregator->sortRows(
            $this->aggregator->groupBy($potentialLeads, 'fuente', 'fuente'),
            'potenciales_sin_seguimiento_mayor_3_dias'
        );

        $commercialPerformance = $this->aggregator->groupBy($current, 'gestor_real_nombre', 'comercial');
        $portalPerformance = $this->aggregator->groupBy($current, 'portal', 'portal');
        $delegationPerformance = $this->aggregator->groupBy($current, 'delegacion_nombre', 'delegacion');

        $payload = [
            'fecha_analisis' => $periods['current_end']->toDateString(),
            'periodos_estandar' => $periods['payload'],
            'resumen_global' => $global,
            'kpis_ejecutivos_exactos' => $this->executiveKpis($global),
            'evolucion_periodo_anterior' => array_merge(
                [
                    'periodo_actual' => $periods['payload']['periodo_actual'],
                    'periodo_anterior' => $periods['payload']['periodo_anterior'],
                ],
                $this->evolutionService->compare($global, $previousGlobal)
            ),
            'bolsa_viva' => [
                'resumen' => $this->aggregator->aggregate($potentialLeads),
                'comerciales' => $pendingCommercials,
                'delegaciones' => $pendingDelegations,
                'fuentes' => $pendingSources,
            ],
            'conversion_descarte' => [
                'resumen' => $global,
                'comerciales' => $commercialPerformance,
                'fuentes' => $portalPerformance,
                'delegaciones' => $delegationPerformance,
            ],
            'tiempos_gestion' => [
                'resumen' => $global,
                'comerciales' => $this->aggregator->groupBy($current, 'responsable_nombre', 'comercial'),
                'delegaciones' => $this->aggregator->groupBy($current, 'delegacion_nombre', 'delegacion'),
                'portales' => $portalPerformance,
            ],
            'calidad_dato' => $this->dataQuality($current),
            'ranking_ejecutivo' => [
                'potenciales_pendientes_comerciales' => array_slice($pendingCommercials, 0, 10),
                'potenciales_pendientes_delegaciones' => array_slice($pendingDelegations, 0, 10),
                'portales_baja_conversion' => $this->lowConversionPortals($portalPerformance),
            ],
            'resumen_ejecutivo' => [
                'prioridades' => $this->executivePriorities($global, $portalPerformance),
            ],
        ];

        return $payload;
    }

    private function loadLeads(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return SalesforceLead::query()
            ->with('activitySummary')
            ->where('created_date', '>=', $start)
            ->where('created_date', '<', $end)
            ->orderBy('created_date')
            ->get();
    }

    private function enrichLeads(Collection $leads, array $commercialUsers, CarbonInterface $periodEnd): array
    {
        return $leads
            ->map(fn (SalesforceLead $lead) => $this->enricher->enrich($lead, $lead->activitySummary, $commercialUsers, $periodEnd))
            ->values()
            ->all();
    }

    private function commercialUsersById(): array
    {
        return SalesforceUser::query()
            ->where('is_active', true)
            ->whereIn('profile_name', ['Comerciales Partner Community', 'Compra/Venta'])
            ->get()
            ->keyBy('salesforce_id')
            ->map(fn (SalesforceUser $user) => [
                'name' => $user->name,
                'profile_name' => $user->profile_name,
            ])
            ->all();
    }

    private function executiveKpis(array $global): array
    {
        return [
            'leads_en_analisis' => $global['leads_totales'],
            'conversion_sobre_total' => $global['conversion_sobre_total'],
            'descarte_sobre_total' => $global['descarte_sobre_total'],
            'potenciales_sin_task_event_registrada' => $global['potenciales_sin_ninguna_task_event'],
            'tiempo_medio_hasta_primera_task_event_horas' => $global['tiempo_medio_respuesta_horas'],
            'tiempo_p90_primera_task_event_horas' => $global['tiempo_p90_respuesta_horas'],
            'con_primera_task_event_registrada' => $global['leads_con_primera_actividad'],
            'primera_gestion_menos_1h_entre_leads_con_task_event' => $global['ratio_respondidos_menos_1h_sobre_actividad'],
            'primera_gestion_menos_1h_sobre_leads_asignados' => $global['ratio_respondidos_menos_1h_sobre_asignados'],
        ];
    }

    private function dataQuality(array $leads): array
    {
        $count = count($leads);

        return [
            'leads_analizados' => $count,
            'responsable_sin_resolver' => $this->countWhere($leads, 'responsable_sin_resolver'),
            'responsable_no_comercial' => $this->countWhere($leads, fn (array $lead) => ! (bool) ($lead['responsable_es_comercial'] ?? false)),
            'responsable_excluido' => $this->countWhere($leads, 'responsable_excluido'),
            'primera_actividad_antes_asignacion' => $this->countWhere($leads, 'primera_actividad_antes_asignacion'),
            'fecha_asignacion_antes_creacion' => $this->countWhere($leads, 'fecha_asignacion_antes_creacion'),
            'gestor_distinto_owner' => $this->countWhere($leads, 'gestor_distinto_owner'),
            'trabajado_distinto_owner' => $this->countWhere($leads, 'trabajado_distinto_owner'),
            'descarte_distinto_owner' => $this->countWhere($leads, 'descarte_distinto_owner'),
            'delegacion_sin_mapear' => $this->countWhere($leads, fn (array $lead) => ! (bool) ($lead['delegacion_mapeada'] ?? false)),
            'portal_desconocido' => $this->countWhere($leads, fn (array $lead) => ($lead['portal'] ?? null) === 'Desconocido'),
        ];
    }

    private function executivePriorities(array $global, array $portalPerformance): array
    {
        $priorities = [];

        if (($global['potenciales_sin_seguimiento_mayor_3_dias'] ?? 0) > 0) {
            $pending = $global['potenciales_sin_seguimiento_mayor_3_dias'];
            $priorities[] = [
                'titulo' => 'Revisar potenciales pendientes de gestion',
                'sugerencia' => "Priorizar la revision de {$pending} potenciales sin seguimiento >3 dias para validar cobertura, trazabilidad y seguimiento comercial.",
            ];
        }

        $priorities[] = [
            'titulo' => 'Validar cobertura y trazabilidad',
            'sugerencia' => 'Analizar la cobertura de Task/Event registrada para separar falta real de gestion de falta de registro en Salesforce.',
        ];

        $lowConversionPortals = $this->lowConversionPortals($portalPerformance);

        if ($lowConversionPortals !== []) {
            $names = collect($lowConversionPortals)
                ->sortBy(function (array $row) {
                    return match ($row['portal']) {
                        'Google Maps' => 0,
                        'Meta' => 1,
                        default => 2,
                    };
                })
                ->pluck('portal')
                ->take(2)
                ->implode(' y ');

            $priorities[] = [
                'titulo' => 'Revisar rendimiento de portales',
                'sugerencia' => "Revisar el rendimiento de {$names}, por su alto volumen de leads y baja conversion.",
            ];
        }

        return array_slice($priorities, 0, 3);
    }

    private function lowConversionPortals(array $portalPerformance): array
    {
        return array_values(array_filter($portalPerformance, function (array $row) {
            return ($row['leads_totales'] ?? 0) >= 1000
                && ($row['conversion_sobre_total'] ?? 0) <= 0.03;
        }));
    }

    private function countWhere(array $leads, string|callable $field): int
    {
        $count = 0;

        foreach ($leads as $lead) {
            $matches = is_callable($field)
                ? $field($lead)
                : (bool) ($lead[$field] ?? false);

            if ($matches) {
                $count++;
            }
        }

        return $count;
    }
}
