<?php

namespace App\Console\Commands;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\ReservationsSales\ReservationsSalesDashboardDatasetService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DebugReservasVentasReportCommand extends Command
{
    protected $signature = 'reports:debug-reservas-ventas
        {--unclassified-portals : Muestra ejemplos minimizados de portales sin clasificar}
        {--reconcile-cohort : Compara IDs de la cohorte KPI y de la auditoría exportable}
        {--from= : Inicio inclusivo YYYY-MM-DD para conciliación}
        {--to= : Fin inclusivo YYYY-MM-DD para conciliación}
        {--date-criterion=created_date : created_date, reservation_date o cv_signed_date}
        {--opportunity-type=all : all, Venta o Tasacion}';

    protected $description = 'Muestra diagnostico de datos sincronizados para Reservas / Ventas.';

    public function handle(
        LeadDelegationNormalizer $normalizer,
        ReservationsSalesDashboardDatasetService $dataset,
    ): int {
        $this->info('Diagnostico Reservas / Ventas');
        $this->line('Total oportunidades: '.SalesforceOpportunity::query()->count());
        $this->line('Min created_date: '.(SalesforceOpportunity::query()->min('created_date') ?: '-'));
        $this->line('Max created_date: '.(SalesforceOpportunity::query()->max('created_date') ?: '-'));
        $this->line('Min reservation_date: '.(SalesforceOpportunity::query()->min('reservation_date') ?: '-'));
        $this->line('Max reservation_date: '.(SalesforceOpportunity::query()->max('reservation_date') ?: '-'));
        $this->line('Min cv_signed_date: '.(SalesforceOpportunity::query()->min('cv_signed_date') ?: '-'));
        $this->line('Max cv_signed_date: '.(SalesforceOpportunity::query()->max('cv_signed_date') ?: '-'));

        $this->newLine();
        $this->table(['RecordType.Name', 'Total'], $this->counts('record_type_name'));
        $this->table(['StageName', 'Total'], $this->counts('stage_name'));
        $this->table(['Portal original', 'Total'], $this->counts('portal_original'));
        $this->table(['Fuente origen Opportunity', 'Total'], $this->counts('opportunity_source_raw'));
        $this->table(['Fuente origen normalizada', 'Total'], $this->counts('opportunity_source_normalized'));
        $this->table(['Portal resuelto', 'Total'], $this->counts('portal_resolved'));
        $this->table(['Origen resolucion portal', 'Total'], $this->counts('portal_resolution_source'));
        $this->table(['Valores no mapeados', 'Total'], $this->unmappedPortalValues());
        $this->table(['Delegacion comercial', 'Total'], $this->commercialDelegationCounts($normalizer));
        $this->table(['Zona', 'Total'], $this->zoneCounts($normalizer));

        $this->newLine();
        $this->line('Reservas vivas: '.$this->liveReservations());
        $this->line('Caidas: '.SalesforceOpportunity::query()->whereRaw('LOWER(stage_name) = ?', ['cerrada perdida'])->count());
        $this->line('CV firmados: '.SalesforceOpportunity::query()
            ->where('cv_signed', true)
            ->where(function ($query): void {
                $query->whereNull('stage_name')->orWhereRaw('LOWER(stage_name) <> ?', ['cerrada perdida']);
            })
            ->count());
        $this->line('Total Sin clasificar: '.SalesforceOpportunity::query()->where('portal_resolved', 'Sin clasificar')->count());

        if ($this->option('unclassified-portals')) {
            $this->newLine();
            $this->table([
                'salesforce_id',
                'portal_original',
                'opportunity_source_raw',
                'opportunity_source_normalized',
                'portal_resolution_source',
            ], $this->unclassifiedExamples());
        }

        if ($this->option('reconcile-cohort') && ! $this->reconcileCohort($dataset)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function counts(string $field): array
    {
        return SalesforceOpportunity::query()
            ->select($field, DB::raw('count(*) as total'))
            ->groupBy($field)
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [$row->{$field} ?: 'NULL', $row->total])
            ->all();
    }

    private function unmappedPortalValues(): array
    {
        return SalesforceOpportunity::query()
            ->select('portal_original', DB::raw('count(*) as total'))
            ->where('portal_resolved', 'Sin clasificar')
            ->groupBy('portal_original')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [$row->portal_original ?: 'NULL', $row->total])
            ->all();
    }

    private function unclassifiedExamples(): array
    {
        return SalesforceOpportunity::query()
            ->where('portal_resolved', 'Sin clasificar')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get([
                'salesforce_id',
                'portal_original',
                'opportunity_source_raw',
                'opportunity_source_normalized',
                'portal_resolution_source',
            ])
            ->map(fn (SalesforceOpportunity $opportunity) => [
                $opportunity->salesforce_id,
                $opportunity->portal_original,
                $opportunity->opportunity_source_raw,
                $opportunity->opportunity_source_normalized,
                $opportunity->portal_resolution_source,
            ])
            ->all();
    }

    private function reconcileCohort(ReservationsSalesDashboardDatasetService $dataset): bool
    {
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $criterion = trim((string) $this->option('date-criterion'));

        if ($from === '' || $to === '') {
            $this->error('La conciliación requiere --from y --to.');

            return false;
        }

        if (! in_array($criterion, ['created_date', 'reservation_date', 'cv_signed_date'], true)) {
            $this->error('El criterio de fecha no es válido.');

            return false;
        }

        $request = Request::create('/diagnostics/reservas-ventas', 'GET', [
            'period' => 'custom',
            'date_criterion' => $criterion,
            'current_start' => $from,
            'current_end' => $to,
            'comparison_start' => $from,
            'comparison_end' => $to,
            'opportunity_type' => (string) $this->option('opportunity-type'),
            'metric' => 'oportunidades_totales',
        ]);
        $kpiIds = collect($dataset->cohortOpportunityIds($request))->unique()->sort()->values();
        $exportIds = collect($dataset->kpiAudit($request)['items'] ?? [])
            ->pluck('opportunity_id')
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $missingFromExport = $kpiIds->diff($exportIds)->values();
        $unexpectedInExport = $exportIds->diff($kpiIds)->values();

        $this->newLine();
        $this->line('IDs cohorte KPI: '.$kpiIds->count());
        $this->line('IDs auditoría exportable: '.$exportIds->count());
        $this->line('A - B: '.$missingFromExport->count());
        $this->line('B - A: '.$unexpectedInExport->count());

        if ($missingFromExport->isNotEmpty() || $unexpectedInExport->isNotEmpty()) {
            $rows = $missingFromExport->map(fn (string $id): array => ['A-B', $id])
                ->merge($unexpectedInExport->map(fn (string $id): array => ['B-A', $id]))
                ->all();
            $this->table(['Conjunto', 'Opportunity ID'], $rows);
        }

        return true;
    }

    private function commercialDelegationCounts(LeadDelegationNormalizer $normalizer): array
    {
        return $this->normalizedCounts($normalizer, 'delegation');
    }

    private function zoneCounts(LeadDelegationNormalizer $normalizer): array
    {
        return $this->normalizedCounts($normalizer, 'zone');
    }

    private function normalizedCounts(LeadDelegationNormalizer $normalizer, string $key): array
    {
        $counts = [];

        SalesforceOpportunity::query()
            ->select(['id', 'owner_delegation'])
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$counts, $normalizer, $key): void {
                foreach ($rows as $row) {
                    $value = $normalizer->normalize($row->owner_delegation)[$key] ?? LeadDelegationNormalizer::UNCLASSIFIED;
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            });

        arsort($counts);

        return collect($counts)
            ->take(15)
            ->map(fn ($total, $label) => [$label, $total])
            ->values()
            ->all();
    }

    private function liveReservations(): int
    {
        return SalesforceOpportunity::query()
            ->where('reservation', true)
            ->where('cv_signed', false)
            ->where(function ($query): void {
                $query->whereNull('stage_name')->orWhereRaw('LOWER(stage_name) <> ?', ['cerrada perdida']);
            })
            ->count();
    }
}
