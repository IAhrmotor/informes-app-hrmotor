<?php

namespace App\Console\Commands;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugReservasVentasReportCommand extends Command
{
    protected $signature = 'reports:debug-reservas-ventas {--unclassified-portals : Muestra ejemplos de portales sin clasificar}';

    protected $description = 'Muestra diagnostico de datos sincronizados para Reservas / Ventas.';

    public function handle(LeadDelegationNormalizer $normalizer): int
    {
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
                'name',
                'account_name',
                'account_phone',
                'account_person_email',
                'account_company_email',
                'portal_original',
                'opportunity_source_raw',
                'opportunity_source_normalized',
                'portal_resolution_source',
                'portal_resolution_debug',
            ], $this->unclassifiedExamples());
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
            ->get()
            ->map(fn (SalesforceOpportunity $opportunity) => [
                $opportunity->salesforce_id,
                $opportunity->name,
                $opportunity->account_name,
                $opportunity->account_phone,
                $opportunity->account_person_email,
                $opportunity->account_company_email,
                $opportunity->portal_original,
                $opportunity->opportunity_source_raw,
                $opportunity->opportunity_source_normalized,
                $opportunity->portal_resolution_source,
                json_encode($opportunity->portal_resolution_debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])
            ->all();
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
