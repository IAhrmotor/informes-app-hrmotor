<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignTypeResolver;
use App\Services\Campaigns\CampaignValueNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DebugGoogleSpendCommand extends Command
{
    protected $signature = 'campaigns:debug-google-spend
        {--from= : Fecha inicial en formato Y-m-d}
        {--to= : Fecha final en formato Y-m-d}
        {--days=30 : Dias hacia atras si no se indica --from}';

    protected $description = 'Reconcilia la inversion de Google Ads por campana para diagnostico de sincronizacion.';

    public function __construct(
        private readonly CampaignTypeResolver $campaignTypeResolver,
    ) {
        parent::__construct();
    }

    public function handle(CampaignValueNormalizer $normalizer): int
    {
        [$start, $end] = $this->period();

        $rows = DB::table('campaign_platform_daily_metrics')
            ->where('platform', 'google_ads')
            ->whereBetween('metric_date', [$start->toDateString(), $end->toDateString()])
            ->select([
                'campaign_id',
                'campaign_name',
                DB::raw('MIN(campaign_status) as campaign_status'),
                DB::raw('MIN(advertising_channel_type) as advertising_channel_type'),
                DB::raw('SUM(COALESCE(spend, 0)) as spend'),
                DB::raw('SUM(COALESCE(impressions, 0)) as impressions'),
                DB::raw('SUM(COALESCE(clicks, 0)) as clicks'),
            ])
            ->groupBy('campaign_id', 'campaign_name')
            ->orderByDesc('spend')
            ->get()
            ->map(fn ($row): array => [
                'campaign_id' => (string) $row->campaign_id,
                'campaign_name' => (string) $row->campaign_name,
                'campaign_status' => (string) $row->campaign_status,
                'advertising_channel_type' => (string) $row->advertising_channel_type,
                'spend' => round((float) $row->spend, 2),
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'campaign_type' => $this->campaignTypeResolver->typeFor('google_ads', $row->campaign_id, $row->campaign_name),
                'excluded' => $this->campaignTypeResolver->shouldExclude($row->campaign_name),
                'has_salesforce_attribution' => $this->hasSalesforceAttribution($row->campaign_id, $row->campaign_name, $normalizer),
            ]);

        $totals = [
            'spend' => round((float) $rows->sum('spend'), 2),
            'impressions' => (int) $rows->sum('impressions'),
            'clicks' => (int) $rows->sum('clicks'),
            'performance_max_spend' => round((float) $rows->filter(fn (array $row) => str_contains(strtoupper((string) $row['advertising_channel_type']), 'PERFORMANCE_MAX'))->sum('spend'), 2),
            'search_spend' => round((float) $rows->filter(fn (array $row) => str_contains(strtoupper((string) $row['advertising_channel_type']), 'SEARCH'))->sum('spend'), 2),
            'display_spend' => round((float) $rows->filter(fn (array $row) => str_contains(strtoupper((string) $row['advertising_channel_type']), 'DISPLAY'))->sum('spend'), 2),
            'excluded_spend' => round((float) $rows->filter(fn (array $row) => $row['excluded'])->sum('spend'), 2),
            'no_salesforce_spend' => round((float) $rows->filter(fn (array $row) => ! $row['has_salesforce_attribution'])->sum('spend'), 2),
            'venta_spend' => round((float) $rows->filter(fn (array $row) => $row['campaign_type'] === 'venta' && ! $row['excluded'])->sum('spend'), 2),
            'tasacion_spend' => round((float) $rows->filter(fn (array $row) => $row['campaign_type'] === 'tasacion' && ! $row['excluded'])->sum('spend'), 2),
        ];

        $this->line(sprintf('Periodo: %s -> %s', $start->toDateString(), $end->toDateString()));
        $this->line(sprintf('Total Google Ads sincronizado: %.2f', $totals['spend']));
        $this->line(sprintf('Performance Max: %.2f', $totals['performance_max_spend']));
        $this->line(sprintf('Search: %.2f', $totals['search_spend']));
        $this->line(sprintf('Display: %.2f', $totals['display_spend']));
        $this->line(sprintf('Excluidas por regla: %.2f', $totals['excluded_spend']));
        $this->line(sprintf('Sin atribucion Salesforce: %.2f', $totals['no_salesforce_spend']));
        $this->line(sprintf('Venta: %.2f', $totals['venta_spend']));
        $this->line(sprintf('Tasacion: %.2f', $totals['tasacion_spend']));

        $this->newLine();
        $this->line('Agrupado por campaign_id');
        $this->table(
            ['campaign_id', 'campaign_name', 'status', 'channel', 'spend', 'impressions', 'clicks', 'type', 'excluded', 'sf_attr'],
            $this->groupByKey($rows, 'campaign_id')
        );

        $this->newLine();
        $this->line('Agrupado por campaign_name');
        $this->table(
            ['campaign_name', 'campaign_id', 'status', 'channel', 'spend', 'impressions', 'clicks', 'type', 'excluded', 'sf_attr'],
            $this->groupByKey($rows, 'campaign_name')
        );

        return self::SUCCESS;
    }

    private function period(): array
    {
        $end = filled($this->option('to'))
            ? CarbonImmutable::parse($this->option('to'))->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $start = filled($this->option('from'))
            ? CarbonImmutable::parse($this->option('from'))->startOfDay()
            : $end->subDays(max((int) $this->option('days'), 1))->startOfDay();

        return [$start, $end];
    }

    private function hasSalesforceAttribution(string $campaignId, string $campaignName, CampaignValueNormalizer $normalizer): bool
    {
        $nameKey = $normalizer->key($campaignName);

        foreach (DB::table('campaign_lead_attributions')
            ->where('platform', 'google_ads')
            ->get(['campaign_id', 'campaign_name']) as $row) {
            if (filled($row->campaign_id) && (string) $row->campaign_id === $campaignId) {
                return true;
            }

            if (filled($row->campaign_name) && $normalizer->key($row->campaign_name) === $nameKey) {
                return true;
            }
        }

        return false;
    }

    private function groupByKey(Collection $rows, string $key): array
    {
        return $rows
            ->groupBy($key)
            ->map(function (Collection $items, string $groupKey) use ($key): array {
                $first = $items->first();

                return [
                    $key => $groupKey,
                    'campaign_id' => $first['campaign_id'] ?? null,
                    'campaign_name' => $first['campaign_name'] ?? null,
                    'status' => $first['campaign_status'] ?? null,
                    'channel' => $first['advertising_channel_type'] ?? null,
                    'spend' => round((float) $items->sum('spend'), 2),
                    'impressions' => (int) $items->sum('impressions'),
                    'clicks' => (int) $items->sum('clicks'),
                    'type' => collect($items->pluck('campaign_type'))->filter()->unique()->implode(', '),
                    'excluded' => $items->contains(fn (array $row): bool => $row['excluded'] === true) ? 'si' : 'no',
                    'sf_attr' => $items->contains(fn (array $row): bool => $row['has_salesforce_attribution'] === true) ? 'si' : 'no',
                ];
            })
            ->values()
            ->all();
    }
}
