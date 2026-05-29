<?php

namespace App\Services\Campaigns;

use App\Models\CampaignAttribution;
use App\Models\CampaignPlatformDailyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CampaignDashboardDatasetService
{
    private const CACHE_TTL_MINUTES = 10;
    private const WINDOW_OPTIONS = [7, 15, 30, 60];

    public function __construct(
        private readonly CampaignValueNormalizer $normalizer,
        private readonly CampaignPerformanceClassifier $classifier,
    ) {
    }

    public function summary(Request $request): array
    {
        return $this->payload($request)['summary'];
    }

    public function campaignRows(Request $request): array
    {
        $payload = $this->payload($request);

        return [
            'ok' => true,
            'items' => $payload['campaigns'],
            'total' => count($payload['campaigns']),
        ];
    }

    public function rankings(Request $request): array
    {
        return [
            'ok' => true,
            'rankings' => $this->payload($request)['rankings'],
        ];
    }

    public function exportRows(Request $request): array
    {
        return $this->payload($request)['campaigns'];
    }

    public function payload(Request $request): array
    {
        $filters = $this->filters($request);
        $period = $this->period($filters);

        return Cache::remember(
            'campaign-dashboard-v1:'.md5(json_encode([
                'filters' => $filters,
                'period' => $period,
                'version' => $this->dataVersion(),
            ])),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildPayload($filters, $period)
        );
    }

    public function filters(Request $request): array
    {
        $window = (int) ($request->integer('attribution_window_days') ?: 30);

        if (! in_array($window, self::WINDOW_OPTIONS, true)) {
            $window = 30;
        }

        return [
            'start_date' => $request->string('start_date')->toString(),
            'end_date' => $request->string('end_date')->toString(),
            'platform' => $request->string('platform')->toString(),
            'account_id' => $request->string('account_id')->toString(),
            'search' => $request->string('search')->toString(),
            'source_acquired' => $request->string('source_acquired')->toString(),
            'medium_acquired' => $request->string('medium_acquired')->toString(),
            'campaign_acquired' => $request->string('campaign_acquired')->toString(),
            'campaign_id' => $request->string('campaign_id')->toString(),
            'campaign_name' => $request->string('campaign_name')->toString(),
            'delegation' => $request->string('delegation')->toString(),
            'zone' => $request->string('zone')->toString(),
            'lead_status' => $request->string('lead_status')->toString(),
            'has_opportunity' => $request->string('has_opportunity')->toString(),
            'has_reservation' => $request->string('has_reservation')->toString(),
            'has_sale' => $request->string('has_sale')->toString(),
            'commercial_user' => $request->string('commercial_user')->toString(),
            'vehicle_interest' => $request->string('vehicle_interest')->toString(),
            'attribution_window_days' => $window,
        ];
    }

    private function buildPayload(array $filters, array $period): array
    {
        $metricRows = $this->metricRows($filters, $period);
        $attributionRows = $this->attributionRows($filters, $period);
        $campaigns = $this->mergeRows($metricRows, $attributionRows, $filters);
        $benchmarks = $this->benchmarks($campaigns);

        $campaigns = array_map(function (array $row) use ($benchmarks): array {
            $row['classification'] = $this->classifier->classify($row, $benchmarks);

            return $row;
        }, $campaigns);

        usort($campaigns, fn (array $a, array $b) => ($b['spend'] <=> $a['spend']) ?: ($b['leads_salesforce'] <=> $a['leads_salesforce']));

        $summary = $this->summaryFromRows($campaigns, $filters, $period);
        $rankings = $this->rankingsFromRows($campaigns);

        return [
            'summary' => $summary,
            'campaigns' => array_values($campaigns),
            'rankings' => $rankings,
        ];
    }

    private function metricRows(array $filters, array $period): array
    {
        $query = DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $period['start'])
            ->where('metric_date', '<=', $period['end']);

        $this->applyMetricFilters($query, $filters);

        return $query
            ->select([
                'platform',
                'account_id',
                DB::raw('MIN(account_name) as account_name'),
                'campaign_id',
                'campaign_name',
                DB::raw('SUM(COALESCE(spend, 0)) as spend'),
                DB::raw('SUM(COALESCE(impressions, 0)) as impressions'),
                DB::raw('SUM(COALESCE(clicks, 0)) as clicks'),
                DB::raw('SUM(COALESCE(platform_leads, 0)) as platform_leads'),
                DB::raw('SUM(CASE WHEN platform_leads IS NOT NULL THEN 1 ELSE 0 END) as platform_leads_rows'),
                DB::raw('SUM(COALESCE(platform_conversions, 0)) as platform_conversions'),
            ])
            ->groupBy('platform', 'account_id', 'campaign_id', 'campaign_name')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->filter(fn (array $row): bool => $this->normalizer->hasClearSalesforceAttribution($row['campaign_id'], $row['campaign_name']))
            ->values()
            ->all();
    }

    private function attributionRows(array $filters, array $period): array
    {
        $query = DB::table('campaign_attributions')
            ->where('lead_created_at', '>=', $period['start_at'])
            ->where('lead_created_at', '<', $period['end_at'])
            ->where('attribution_window_days', $filters['attribution_window_days']);

        $this->applyAttributionFilters($query, $filters);

        return $query
            ->select([
                'platform',
                'account_id',
                'campaign_id',
                'campaign_name',
                DB::raw('MIN(source_acquired) as source_acquired'),
                DB::raw('MIN(medium_acquired) as medium_acquired'),
                DB::raw('MIN(campaign_acquired) as campaign_acquired'),
                DB::raw('MIN(match_status) as match_status'),
                DB::raw('COUNT(DISTINCT lead_id) as leads_salesforce'),
                DB::raw('COUNT(DISTINCT CASE WHEN has_opportunity = 1 THEN opportunity_id END) as opportunities'),
                DB::raw('SUM(CASE WHEN has_reservation = 1 THEN 1 ELSE 0 END) as reservations'),
                DB::raw('SUM(CASE WHEN has_reservation = 1 AND has_sale = 0 AND has_fallen_reservation = 0 THEN 1 ELSE 0 END) as live_reservations'),
                DB::raw('SUM(CASE WHEN has_fallen_reservation = 1 THEN 1 ELSE 0 END) as fallen_reservations'),
                DB::raw('SUM(CASE WHEN has_sale = 1 THEN 1 ELSE 0 END) as sales'),
                DB::raw('SUM(CASE WHEN sale_amount IS NOT NULL THEN sale_amount ELSE 0 END) as sale_amount'),
                DB::raw('SUM(CASE WHEN sale_amount IS NOT NULL THEN 1 ELSE 0 END) as sale_amount_rows'),
            ])
            ->groupBy('platform', 'account_id', 'campaign_id', 'campaign_name')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->filter(fn (array $row): bool => $this->normalizer->hasClearSalesforceAttribution($row['campaign_id'], $row['campaign_name']))
            ->values()
            ->all();
    }

    private function mergeRows(array $metricRows, array $attributionRows, array $filters): array
    {
        $rows = [];
        $hasAttributionSpecificFilters = $this->hasAttributionSpecificFilters($filters);

        foreach ($metricRows as $row) {
            if ($hasAttributionSpecificFilters) {
                continue;
            }

            $key = $this->rowKey($row);
            $rows[$key] = $this->emptyCampaignRow($row);
            $rows[$key]['account_name'] = $row['account_name'];
            $rows[$key]['spend'] = (float) $row['spend'];
            $rows[$key]['impressions'] = (int) $row['impressions'];
            $rows[$key]['clicks'] = (int) $row['clicks'];
            $rows[$key]['platform_leads'] = (int) $row['platform_leads_rows'] > 0 ? (int) $row['platform_leads'] : null;
            $rows[$key]['platform_conversions'] = (float) $row['platform_conversions'];
            $rows[$key]['match_status'] = 'Sin leads Salesforce';
        }

        foreach ($attributionRows as $row) {
            $key = $this->rowKey($row);
            $rows[$key] ??= $this->emptyCampaignRow($row);

            $rows[$key]['source_acquired'] = $row['source_acquired'];
            $rows[$key]['medium_acquired'] = $row['medium_acquired'];
            $rows[$key]['campaign_acquired'] = $row['campaign_acquired'];
            $rows[$key]['match_status'] = $row['match_status'] ?: 'Sin inversión asociada';
            $rows[$key]['leads_salesforce'] = (int) $row['leads_salesforce'];
            $rows[$key]['opportunities'] = (int) $row['opportunities'];
            $rows[$key]['reservations'] = (int) $row['reservations'];
            $rows[$key]['live_reservations'] = (int) $row['live_reservations'];
            $rows[$key]['fallen_reservations'] = (int) $row['fallen_reservations'];
            $rows[$key]['sales'] = (int) $row['sales'];
            $rows[$key]['sale_amount'] = (int) $row['sale_amount_rows'] > 0 ? (float) $row['sale_amount'] : null;
        }

        return array_values(array_map(fn (array $row) => $this->withDerivedState($this->withRatios($row)), $rows));
    }

    private function emptyCampaignRow(array $source): array
    {
        return [
            'platform' => $source['platform'] ?? 'unknown',
            'account_id' => $source['account_id'] ?? null,
            'account_name' => $source['account_name'] ?? null,
            'source_acquired' => $source['source_acquired'] ?? null,
            'medium_acquired' => $source['medium_acquired'] ?? null,
            'campaign_acquired' => $source['campaign_acquired'] ?? null,
            'campaign_id' => $source['campaign_id'] ?? null,
            'campaign_name' => $source['campaign_name'] ?? null,
            'match_status' => $source['match_status'] ?? null,
            'spend' => 0.0,
            'impressions' => 0,
            'clicks' => 0,
            'platform_leads' => null,
            'platform_conversions' => null,
            'leads_salesforce' => 0,
            'opportunities' => 0,
            'reservations' => 0,
            'live_reservations' => 0,
            'fallen_reservations' => 0,
            'sales' => 0,
            'sale_amount' => null,
        ];
    }

    private function withRatios(array $row): array
    {
        $saleAmount = $row['sale_amount'];
        $spend = (float) $row['spend'];

        return array_merge($row, [
            'ctr' => $this->divide($row['clicks'], $row['impressions']),
            'cpc' => $this->divide($spend, $row['clicks']),
            'cost_per_lead' => $this->divide($spend, $row['leads_salesforce']),
            'cost_per_opportunity' => $this->divide($spend, $row['opportunities']),
            'cost_per_reservation' => $this->divide($spend, $row['reservations']),
            'cost_per_sale' => $this->divide($spend, $row['sales']),
            'roas' => $saleAmount !== null ? $this->divide($saleAmount, $spend) : null,
            'estimated_roi' => $saleAmount !== null ? $this->divide($saleAmount - $spend, $spend) : null,
            'click_to_lead_salesforce' => $this->divide($row['leads_salesforce'], $row['clicks']),
            'click_to_lead_platform' => $row['platform_leads'] !== null ? $this->divide($row['platform_leads'], $row['clicks']) : null,
            'lead_to_opportunity' => $this->divide($row['opportunities'], $row['leads_salesforce']),
            'opportunity_to_reservation' => $this->divide($row['reservations'], $row['opportunities']),
            'reservation_to_sale' => $this->divide($row['sales'], $row['reservations']),
            'lead_to_sale' => $this->divide($row['sales'], $row['leads_salesforce']),
        ]);
    }

    private function withDerivedState(array $row): array
    {
        $spend = (float) ($row['spend'] ?? 0);
        $leads = (int) ($row['leads_salesforce'] ?? 0);
        $status = $row['match_status'] ?? null;

        if ($spend > 0 && $leads === 0) {
            $status = 'Sin leads Salesforce';
        } elseif ($leads > 0 && $spend <= 0.0) {
            $status = 'Sin inversión asociada';
        } elseif ($status === null && $leads > 0) {
            $status = 'Cruzada';
        }

        $row['match_status'] = $status ?? 'Sin datos';

        return $row;
    }

    private function summaryFromRows(array $rows, array $filters, array $period): array
    {
        $totals = $this->withRatios([
            'platform' => null,
            'account_id' => null,
            'account_name' => null,
            'source_acquired' => null,
            'medium_acquired' => null,
            'campaign_acquired' => null,
            'campaign_id' => null,
            'campaign_name' => null,
            'spend' => array_sum(array_column($rows, 'spend')),
            'impressions' => array_sum(array_column($rows, 'impressions')),
            'clicks' => array_sum(array_column($rows, 'clicks')),
            'platform_leads' => collect($rows)->contains(fn (array $row) => $row['platform_leads'] !== null)
                ? array_sum(array_map(fn (array $row) => (int) ($row['platform_leads'] ?? 0), $rows))
                : null,
            'platform_conversions' => array_sum(array_column($rows, 'platform_conversions')),
            'leads_salesforce' => array_sum(array_column($rows, 'leads_salesforce')),
            'opportunities' => array_sum(array_column($rows, 'opportunities')),
            'reservations' => array_sum(array_column($rows, 'reservations')),
            'live_reservations' => array_sum(array_column($rows, 'live_reservations')),
            'fallen_reservations' => array_sum(array_column($rows, 'fallen_reservations')),
            'sales' => array_sum(array_column($rows, 'sales')),
            'sale_amount' => collect($rows)->contains(fn (array $row) => $row['sale_amount'] !== null)
                ? array_sum(array_map(fn (array $row) => (float) ($row['sale_amount'] ?? 0), $rows))
                : null,
        ]);

        return [
            'ok' => count($rows) > 0,
            'periodo_actual' => [
                'inicio' => $period['start'],
                'fin' => $period['end'],
            ],
            'attribution_window_days' => $filters['attribution_window_days'],
            'datos_actualizados' => $this->lastUpdated()?->toDateTimeString(),
            'kpis' => $totals,
            'warnings' => $this->warnings($rows, $totals),
            'diagnostics' => $this->diagnostics($rows, $period, $filters),
            'filters' => $this->filterOptions(),
        ];
    }

    private function rankingsFromRows(array $rows): array
    {
        return [
            'top_spend' => $this->top($rows, 'spend'),
            'top_leads_salesforce' => $this->top($rows, 'leads_salesforce'),
            'top_opportunities' => $this->top($rows, 'opportunities'),
            'top_reservations' => $this->top($rows, 'reservations'),
            'top_sales' => $this->top($rows, 'sales'),
            'best_cost_per_sale' => $this->top($rows, 'cost_per_sale', ascending: true, requireValue: true),
            'worst_cost_per_sale' => $this->top($rows, 'cost_per_sale', requireValue: true),
            'high_spend_low_conversion' => collect($rows)->sortByDesc('spend')->sortBy('sales')->take(5)->values()->all(),
            'many_leads_few_sales' => collect($rows)->sortByDesc('leads_salesforce')->sortBy('sales')->take(5)->values()->all(),
            'review_tracking' => $this->classification($rows, 'Revisar tracking'),
            'review_investment_tracking' => $this->classification($rows, 'Revisar inversión/tracking'),
            'boost' => $this->classification($rows, 'Potenciar'),
            'review' => $this->classification($rows, 'Revisar'),
            'stop' => $this->classification($rows, 'Parar'),
        ];
    }

    private function top(array $rows, string $key, bool $ascending = false, bool $requireValue = false): array
    {
        return collect($rows)
            ->when($requireValue, fn ($collection) => $collection->filter(fn (array $row) => $row[$key] !== null))
            ->sortBy($key, SORT_REGULAR, ! $ascending)
            ->take(5)
            ->values()
            ->all();
    }

    private function classification(array $rows, string $classification): array
    {
        return collect($rows)
            ->where('classification', $classification)
            ->sortByDesc('spend')
            ->take(5)
            ->values()
            ->all();
    }

    private function benchmarks(array $rows): array
    {
        $costs = collect($rows)->pluck('cost_per_sale')->filter(fn ($value) => $value !== null && $value > 0);
        $roas = collect($rows)->pluck('roas')->filter(fn ($value) => $value !== null && $value > 0);

        return [
            'avg_cost_per_sale' => $costs->isNotEmpty() ? round($costs->avg(), 4) : null,
            'avg_roas' => $roas->isNotEmpty() ? round($roas->avg(), 4) : null,
        ];
    }

    private function warnings(array $rows, array $totals): array
    {
        $warnings = [];

        if (! filled(config('services.meta_ads.access_token')) || config('services.meta_ads.ad_account_ids') === []) {
            $warnings[] = 'Las credenciales de Meta Ads no estan configuradas. Se muestran datos disponibles de Salesforce y/o ultima inversion cacheada.';
        }

        if (! filled(config('services.google_ads.developer_token')) || config('services.google_ads.customer_ids') === []) {
            $warnings[] = 'Las credenciales de Google Ads no estan configuradas. Se muestran datos disponibles de Salesforce y/o ultima inversion cacheada.';
        }

        if (($totals['leads_salesforce'] ?? 0) > 0 && (float) ($totals['spend'] ?? 0) <= 0.0) {
            $warnings[] = 'Hay campanas Salesforce sin inversion asociada. Revisar IDs/nombres de campana.';
        }

        if ((float) ($totals['spend'] ?? 0) > 0.0 && (int) ($totals['leads_salesforce'] ?? 0) === 0) {
            $warnings[] = 'Hay inversion de plataforma sin leads Salesforce asociados.';
        }

        if (CampaignAttribution::query()->count() === 0) {
            $warnings[] = 'No hay atribuciones construidas para el periodo seleccionado.';
        }

        if (($totals['sales'] ?? 0) > 0 && ($totals['sale_amount'] ?? null) === null) {
            $warnings[] = 'No hay campo local de importe vendido reutilizable en Reservas/Ventas. ROAS y ROI quedan sin dato hasta mapear el campo Salesforce correcto.';
        }

        $lastSyncedAt = $this->lastMetricSyncedAt();
        if ($lastSyncedAt === null) {
            $warnings[] = 'No hay inversion sincronizada todavia en campaign_platform_daily_metrics.';
        } elseif ($lastSyncedAt->lessThan(now()->subHours(36))) {
            $warnings[] = 'Los datos de inversion no se han actualizado en las ultimas 36 horas.';
        }

        return array_values(array_unique($warnings));
    }

    private function diagnostics(array $rows, array $period, array $filters): array
    {
        $attributionBase = DB::table('campaign_attributions')
            ->where('lead_created_at', '>=', $period['start_at'])
            ->where('lead_created_at', '<', $period['end_at'])
            ->where('attribution_window_days', $filters['attribution_window_days']);

        return [
            'last_meta_sync' => DB::table('campaign_platform_daily_metrics')->where('platform', 'meta')->max('synced_at'),
            'last_google_sync' => DB::table('campaign_platform_daily_metrics')->where('platform', 'google_ads')->max('synced_at'),
            'last_attribution_build' => DB::table('campaign_attributions')->max('updated_at'),
            'meta_metric_rows' => DB::table('campaign_platform_daily_metrics')->where('platform', 'meta')->count(),
            'google_metric_rows' => DB::table('campaign_platform_daily_metrics')->where('platform', 'google_ads')->count(),
            'salesforce_leads_with_campaign_period' => $this->leadsWithAcquisitionNotNull($period),
            'valid_candidate_leads' => (clone $attributionBase)->count(),
            'built_attributions' => (clone $attributionBase)->count(),
            'campaigns_spend_without_salesforce_leads' => collect($rows)->filter(fn (array $row) => (float) $row['spend'] > 0 && (int) $row['leads_salesforce'] === 0)->count(),
            'campaigns_salesforce_without_spend' => collect($rows)->filter(fn (array $row) => (int) $row['leads_salesforce'] > 0 && (float) $row['spend'] <= 0.0)->count(),
            'campaigns_matched_by_id' => collect($rows)->filter(fn (array $row) => str_contains((string) ($row['match_status'] ?? ''), 'ID'))->count(),
            'campaigns_matched_by_name' => collect($rows)->filter(fn (array $row) => str_contains((string) ($row['match_status'] ?? ''), 'nombre'))->count(),
        ];
    }

    private function leadsWithAcquisitionNotNull(array $period): int
    {
        return DB::table('salesforce_leads')
            ->where('created_date', '>=', $period['start_at'])
            ->where('created_date', '<', $period['end_at'])
            ->where(function ($query): void {
                foreach ([
                    'campaign_acquired',
                    'acquired_id',
                    'content_acquired',
                    'fuente_origen',
                    'medio_origen',
                ] as $field) {
                    $query->orWhere(function ($subQuery) use ($field): void {
                        $subQuery->whereNotNull($field)->where($field, '<>', '');
                    });
                }
            })
            ->count();
    }

    private function applyMetricFilters($query, array $filters): void
    {
        foreach (['platform', 'account_id', 'campaign_id', 'campaign_name'] as $field) {
            if (filled($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (filled($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($subQuery) use ($search): void {
                $subQuery
                    ->where('campaign_id', 'like', $search)
                    ->orWhere('campaign_name', 'like', $search);
            });
        }
    }

    private function applyAttributionFilters($query, array $filters): void
    {
        foreach ([
            'platform',
            'account_id',
            'source_acquired',
            'medium_acquired',
            'campaign_acquired',
            'campaign_id',
            'campaign_name',
            'lead_status',
            'vehicle_interest',
        ] as $field) {
            if (filled($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (filled($filters['delegation'])) {
            $query->where('lead_delegation', $filters['delegation']);
        }

        if (filled($filters['zone'])) {
            $query->where('lead_zone', $filters['zone']);
        }

        if (filled($filters['commercial_user'])) {
            $query->where(function ($subQuery) use ($filters): void {
                $subQuery
                    ->where('commercial_user_id', $filters['commercial_user'])
                    ->orWhere('commercial_user_name', $filters['commercial_user']);
            });
        }

        if (filled($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($subQuery) use ($search): void {
                $subQuery
                    ->where('campaign_id', 'like', $search)
                    ->orWhere('campaign_name', 'like', $search)
                    ->orWhere('campaign_acquired', 'like', $search)
                    ->orWhere('source_acquired', 'like', $search)
                    ->orWhere('medium_acquired', 'like', $search);
            });
        }

        foreach ([
            'has_opportunity' => 'has_opportunity',
            'has_reservation' => 'has_reservation',
            'has_sale' => 'has_sale',
        ] as $filter => $field) {
            $boolean = $this->booleanFilter($filters[$filter]);

            if ($boolean !== null) {
                $query->where($field, $boolean);
            }
        }
    }

    private function filterOptions(): array
    {
        return [
            'platforms' => $this->distinctFromBoth('platform'),
            'accounts' => DB::table('campaign_platform_daily_metrics')
                ->select('account_id', DB::raw('MIN(account_name) as account_name'))
                ->whereNotNull('account_id')
                ->groupBy('account_id')
                ->orderBy('account_id')
                ->get()
                ->map(fn ($row) => ['id' => $row->account_id, 'name' => $row->account_name])
                ->all(),
            'sources' => $this->distinct('campaign_attributions', 'source_acquired'),
            'mediums' => $this->distinct('campaign_attributions', 'medium_acquired'),
            'campaigns_acquired' => $this->distinct('campaign_attributions', 'campaign_acquired'),
            'campaign_ids' => $this->distinctFromBoth('campaign_id'),
            'campaign_names' => $this->distinctFromBoth('campaign_name'),
            'delegations' => $this->distinct('campaign_attributions', 'lead_delegation'),
            'zones' => $this->distinct('campaign_attributions', 'lead_zone'),
            'lead_statuses' => $this->distinct('campaign_attributions', 'lead_status'),
            'commercials' => DB::table('campaign_attributions')
                ->select('commercial_user_id', 'commercial_user_name')
                ->whereNotNull('commercial_user_name')
                ->distinct()
                ->orderBy('commercial_user_name')
                ->get()
                ->map(fn ($row) => ['id' => $row->commercial_user_id ?: $row->commercial_user_name, 'name' => $row->commercial_user_name])
                ->all(),
            'vehicles' => $this->distinct('campaign_attributions', 'vehicle_interest'),
            'windows' => self::WINDOW_OPTIONS,
        ];
    }

    private function distinct(string $table, string $column): array
    {
        return DB::table($table)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }

    private function distinctFromBoth(string $column): array
    {
        return collect($this->distinct('campaign_attributions', $column))
            ->merge($this->distinct('campaign_platform_daily_metrics', $column))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function period(array $filters): array
    {
        $end = $this->parseDate($filters['end_date'], CarbonImmutable::now())->endOfDay();
        $start = $this->parseDate($filters['start_date'], $end->subDays(30))->startOfDay();

        if ($start->greaterThan($end)) {
            $start = $end->subDays(30)->startOfDay();
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'start_at' => $start,
            'end_at' => $end->addDay()->startOfDay(),
        ];
    }

    private function rowKey(array $row): string
    {
        return implode('|', [
            $row['platform'] ?? 'unknown',
            $row['account_id'] ?? '',
            $row['campaign_id'] ?? '',
            $this->normalizer->key($row['campaign_name'] ?? ''),
        ]);
    }

    private function hasAttributionSpecificFilters(array $filters): bool
    {
        foreach ([
            'source_acquired',
            'medium_acquired',
            'campaign_acquired',
            'delegation',
            'zone',
            'lead_status',
            'has_opportunity',
            'has_reservation',
            'has_sale',
            'commercial_user',
            'vehicle_interest',
        ] as $key) {
            if (filled($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    private function booleanFilter(?string $value): ?bool
    {
        if (! filled($value) || $value === 'all') {
            return null;
        }

        return in_array($value, ['1', 'true', 'yes', 'si', 's'], true)
            ? true
            : (in_array($value, ['0', 'false', 'no', 'n'], true) ? false : null);
    }

    private function divide(float|int|null $numerator, float|int|null $denominator): ?float
    {
        if ($denominator === null || (float) $denominator === 0.0) {
            return null;
        }

        return round(((float) $numerator) / ((float) $denominator), 4);
    }

    private function parseDate(?string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (blank($value)) {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function lastUpdated(): ?CarbonImmutable
    {
        $updated = max(
            CampaignAttribution::query()->max('updated_at') ?: '',
            CampaignPlatformDailyMetric::query()->max('updated_at') ?: ''
        );

        return $updated ? CarbonImmutable::parse($updated) : null;
    }

    private function lastMetricSyncedAt(): ?CarbonImmutable
    {
        $syncedAt = CampaignPlatformDailyMetric::query()->max('synced_at');

        return $syncedAt ? CarbonImmutable::parse($syncedAt) : null;
    }

    private function dataVersion(): array
    {
        return [
            'attributions_count' => CampaignAttribution::query()->count(),
            'metrics_count' => CampaignPlatformDailyMetric::query()->count(),
            'attributions_updated_at' => CampaignAttribution::query()->max('updated_at'),
            'metrics_updated_at' => CampaignPlatformDailyMetric::query()->max('updated_at'),
            'dashboard_cache_version' => Cache::get('campaign_dashboard_cache_version', 1),
        ];
    }
}
