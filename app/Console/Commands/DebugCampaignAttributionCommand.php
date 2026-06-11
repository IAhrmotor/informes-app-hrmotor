<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignTypeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DebugCampaignAttributionCommand extends Command
{
    protected $signature = 'campaigns:debug-attribution
        {--from= : Fecha inicial explicita en formato Y-m-d}
        {--to= : Fecha final explicita en formato Y-m-d}';

    protected $description = 'Audita la coherencia entre salesforce_leads y campaign_lead_attributions para Campanas.';

    public function handle(CampaignTypeResolver $resolver): int
    {
        $start = CarbonImmutable::parse($this->option('from') ?: now()->startOfMonth()->toDateString())->startOfDay();
        $end = CarbonImmutable::parse($this->option('to') ?: now()->toDateString())->addDay()->startOfDay();

        $leads = DB::table('salesforce_leads')
            ->where('created_date', '>=', $start)
            ->where('created_date', '<', $end)
            ->select(['salesforce_id', 'campaign_acquired'])
            ->get()
            ->map(function (object $lead) use ($resolver): array {
                $campaignName = $lead->campaign_acquired;
                $reason = $resolver->excludedReason($campaignName);

                return [
                    'lead_id' => (string) $lead->salesforce_id,
                    'source_campaign_name' => (string) $campaignName,
                    'excluded_reason' => $reason,
                    'campaign_type' => $reason === null ? $resolver->sourceCampaignType($campaignName) : null,
                ];
            });

        $validLeads = $leads->filter(fn (array $row): bool => $row['excluded_reason'] === null)->values();
        $attributions = DB::table('campaign_lead_attributions')
            ->where('lead_created_date', '>=', $start)
            ->where('lead_created_date', '<', $end)
            ->select(['lead_id', 'source_campaign_name', 'campaign_name', 'campaign_type'])
            ->get()
            ->map(fn (object $row): array => [
                'lead_id' => (string) $row->lead_id,
                'source_campaign_name' => (string) ($row->source_campaign_name ?? ''),
                'campaign_name' => (string) ($row->campaign_name ?? ''),
                'campaign_type' => (string) ($row->campaign_type ?? ''),
            ])
            ->values();

        $this->line('Periodo: '.$start->toDateString().' a '.$end->subDay()->toDateString());
        $this->line('Tabla base: salesforce_leads');
        $this->newLine();

        $this->renderScope('Todas', $validLeads, $attributions);
        $this->renderScope('Venta', $validLeads->where('campaign_type', 'venta')->values(), $attributions->where('campaign_type', 'venta')->values());
        $this->renderScope('Tasacion', $validLeads->where('campaign_type', 'tasacion')->values(), $attributions->where('campaign_type', 'tasacion')->values());

        $this->newLine();
        $this->table(
            ['motivo_exclusion', 'total'],
            $this->groupCounts(
                $leads->filter(fn (array $row): bool => $row['excluded_reason'] !== null),
                'excluded_reason'
            )
        );

        $this->newLine();
        $this->line('Desglose por source_campaign_name valido');
        $this->table(['source_campaign_name', 'total'], $this->groupCounts($validLeads, 'source_campaign_name'));

        $this->newLine();
        $this->line('Desglose visible en campaign_lead_attributions');
        $this->table(['campaign_name', 'total'], $this->groupCounts($attributions, 'campaign_name'));

        $this->newLine();
        $this->line('Desglose por campaign_type en campaign_lead_attributions');
        $this->table(['campaign_type', 'total'], $this->groupCounts($attributions, 'campaign_type'));

        return self::SUCCESS;
    }

    private function renderScope(string $label, Collection $validLeads, Collection $attributions): void
    {
        $validIds = $validLeads->pluck('lead_id');
        $attributionIds = $attributions->pluck('lead_id');

        $this->line(sprintf(
            '[%s] validos=%d | atribuciones=%d | huerfanas=%d | faltantes=%d',
            $label,
            $validLeads->count(),
            $attributions->count(),
            $attributionIds->diff($validIds)->count(),
            $validIds->diff($attributionIds)->count(),
        ));
    }

    private function groupCounts(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (array $row): string => (string) ($row[$field] ?: '[vacio]'))
            ->map(fn (Collection $items, string $value): array => [$field => $value, 'total' => $items->count()])
            ->sortByDesc('total')
            ->values()
            ->all();
    }
}
