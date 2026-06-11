<?php

namespace App\Services\Campaigns;

use App\Models\CampaignAttribution;
use App\Models\CampaignPlatformDailyMetric;
use App\Support\ReportUserAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CampaignDashboardDatasetService
{
    private const CACHE_TTL_MINUTES = 10;
    private const REPORT_TIMEZONE = 'Europe/Madrid';

    public function __construct(
        private readonly CampaignValueNormalizer $normalizer,
        private readonly CampaignPerformanceClassifier $classifier,
        private readonly CampaignSaleAmountResolver $saleAmountResolver,
        private readonly CampaignTypeResolver $campaignTypeResolver,
    ) {
    }

    public function summary(Request $request): array
    {
        $summary = $this->payload($request)['summary'];

        if (! ReportUserAccess::isAdmin($request)) {
            $summary['warnings'] = [];
            unset($summary['diagnostics']);
        }

        return $summary;
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
            'campaign-dashboard-v3:'.md5(json_encode([
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
        $requestedType = $this->normalizeContext($request->string('campaign_type')->toString());
        $context = $requestedType !== 'all'
            ? $requestedType
            : $request->string('context')->toString();

        return [
            'start_date' => $request->string('start_date')->toString(),
            'end_date' => $request->string('end_date')->toString(),
            'platform' => $request->string('platform')->toString(),
            'account_id' => $request->string('account_id')->toString(),
            'search' => $request->string('search')->toString(),
            'context' => $this->normalizeContext($context),
            'campaign_status' => $request->string('campaign_status')->toString() ?: 'active',
            'campaign_source_type' => $request->string('campaign_source_type')->toString(),
            'source_acquired' => $request->string('source_acquired')->toString(),
            'medium_acquired' => $request->string('medium_acquired')->toString(),
            'campaign_acquired' => $request->string('campaign_acquired')->toString(),
            'campaign_id' => $request->string('campaign_id')->toString(),
            'campaign_name' => $this->campaignNameFilter($request->input('campaign_name')),
            'campaign_type' => $requestedType === 'all' ? '' : $requestedType,
            'delegation' => $request->string('delegation')->toString(),
            'zone' => $request->string('zone')->toString(),
            'lead_status' => $request->string('lead_status')->toString(),
            'has_opportunity' => $request->string('has_opportunity')->toString(),
            'has_reservation' => $request->string('has_reservation')->toString(),
            'has_sale' => $request->string('has_sale')->toString(),
            'classification' => $request->string('classification')->toString(),
            'commercial_user' => $request->string('commercial_user')->toString(),
            'vehicle_interest' => $request->string('vehicle_interest')->toString(),
        ];
    }

    private function buildPayload(array $filters, array $period): array
    {
        $metricRows = $this->metricRows($filters, $period);
        $attributionRows = $this->attributionRows($filters, $period);
        $allCampaigns = $this->mergeRows($metricRows, $attributionRows, $filters);
        $benchmarks = $this->benchmarks($this->platformRows($allCampaigns));

        $allCampaigns = array_map(function (array $row) use ($benchmarks): array {
            $row['classification'] = $this->classifier->classify($row, $benchmarks);

            return $row;
        }, $allCampaigns);

        $campaigns = $this->mainCampaignRows($allCampaigns, $filters);

        usort($campaigns, fn (array $a, array $b) => ($b['spend'] <=> $a['spend']) ?: ($b['leads_salesforce'] <=> $a['leads_salesforce']));

        $summary = $this->summaryFromRows($campaigns, $allCampaigns, $filters, $period);
        $rankings = $this->rankingsFromRows($campaigns, $filters);

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
                DB::raw('MIN(campaign_status) as campaign_status'),
                DB::raw('MIN(campaign_effective_status) as campaign_effective_status'),
                DB::raw('MIN(campaign_start_date) as campaign_start_date'),
                DB::raw('MIN(campaign_end_date) as campaign_end_date'),
                DB::raw('MIN(advertising_channel_type) as advertising_channel_type'),
                DB::raw('MIN(advertising_channel_sub_type) as advertising_channel_sub_type'),
                DB::raw('MAX(CASE WHEN COALESCE(spend, 0) > 0 THEN metric_date ELSE NULL END) as last_spend_date'),
                DB::raw("'platform_campaign' as campaign_source_type"),
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
            ->filter(fn (array $row): bool => $this->includeCampaignForContext($row, $filters))
            ->values()
            ->all();
    }

    private function attributionRows(array $filters, array $period): array
    {
        $query = DB::table('campaign_lead_attributions as cla')
            ->leftJoin('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->where('cla.lead_created_date', '>=', $period['start_at'])
            ->where('cla.lead_created_date', '<', $period['end_at']);

        $this->applyLeadAttributionFilters($query, $filters, 'cla');

        return $query
            ->select([
                'cla.platform',
                DB::raw('NULL as account_id'),
                'cla.campaign_id',
                'cla.campaign_name',
                DB::raw("CASE WHEN cla.platform = 'salesforce' THEN 'salesforce_campaign_without_spend' ELSE 'platform_campaign' END as campaign_source_type"),
                DB::raw('MIN(cla.source_acquired) as source_acquired'),
                DB::raw('MIN(cla.medium_acquired) as medium_acquired'),
                DB::raw('MIN(cla.campaign_acquired) as campaign_acquired'),
                DB::raw('MIN(cla.source_campaign_name) as source_campaign_name'),
                DB::raw('MIN(cla.campaign_type) as campaign_type'),
                DB::raw('MIN(cla.acquired_id) as acquired_id'),
                DB::raw('MIN(cla.content_acquired) as content_acquired'),
                DB::raw("MIN(CASE WHEN cla.has_opportunity = 1 THEN 'Cruzada por ID' ELSE 'Sin leads Salesforce' END) as match_status"),
                DB::raw('COUNT(DISTINCT cla.lead_id) as leads_salesforce'),
                DB::raw('COUNT(DISTINCT CASE WHEN cla.has_opportunity = 1 THEN cla.opportunity_id END) as opportunities'),
                DB::raw('SUM(CASE WHEN cla.has_reservation = 1 THEN 1 ELSE 0 END) as reservations'),
                DB::raw('SUM(CASE WHEN cla.has_reservation = 1 AND cla.has_sale = 0 THEN 1 ELSE 0 END) as live_reservations'),
                DB::raw('SUM(CASE WHEN cla.has_reservation = 1 AND cla.has_sale = 1 THEN 1 ELSE 0 END) as fallen_reservations'),
                DB::raw('SUM(CASE WHEN cla.has_sale = 1 THEN 1 ELSE 0 END) as sales'),
                DB::raw('SUM(CASE WHEN cla.has_sale = 1 THEN COALESCE(cla.sold_amount, CASE WHEN COALESCE(so.opo_for_importe_total, 0) > 0 THEN so.opo_for_importe_total ELSE NULL END) ELSE 0 END) as sale_amount'),
                DB::raw('SUM(CASE WHEN cla.has_sale = 1 AND (cla.sold_amount IS NOT NULL OR COALESCE(so.opo_for_importe_total, 0) > 0) THEN 1 ELSE 0 END) as sale_amount_rows'),
                DB::raw('SUM(CASE WHEN cla.has_opportunity = 1 AND cla.campaign_type = \'tasacion\' THEN 1 ELSE 0 END) as appraisals_generated'),
                DB::raw('SUM(CASE WHEN cla.has_purchase = 1 THEN 1 ELSE 0 END) as purchases'),
                DB::raw('SUM(CASE WHEN cla.has_purchase = 1 THEN ABS(COALESCE(so.opo_for_importe_total, 0)) ELSE 0 END) as appraisal_amount'),
                DB::raw('SUM(CASE WHEN cla.has_purchase = 1 AND so.opo_for_importe_total IS NOT NULL THEN 1 ELSE 0 END) as appraisal_amount_rows'),
                DB::raw('MIN(cla.lead_status) as lead_status'),
                DB::raw('MIN(cla.lead_delegation) as lead_delegation'),
                DB::raw('MIN(cla.lead_zone) as lead_zone'),
                DB::raw('MIN(cla.commercial_user_id) as commercial_user_id'),
                DB::raw('MIN(cla.commercial_user_name) as commercial_user_name'),
                DB::raw('MIN(cla.vehicle_interest) as vehicle_interest'),
            ])
            ->groupBy('cla.platform', 'cla.campaign_id', 'cla.campaign_name')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->filter(fn (array $row): bool => $this->includeCampaignForContext($row, $filters))
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
            $rows[$key]['campaign_status'] = $row['campaign_status'] ?? null;
            $rows[$key]['campaign_effective_status'] = $row['campaign_effective_status'] ?? null;
            $rows[$key]['campaign_start_date'] = $row['campaign_start_date'] ?? null;
            $rows[$key]['campaign_end_date'] = $row['campaign_end_date'] ?? null;
            $rows[$key]['advertising_channel_type'] = $row['advertising_channel_type'] ?? null;
            $rows[$key]['advertising_channel_sub_type'] = $row['advertising_channel_sub_type'] ?? null;
            $rows[$key]['last_spend_date'] = $row['last_spend_date'] ?? null;
            $rows[$key]['campaign_status_label'] = $this->campaignStatusLabel($rows[$key]);
            $rows[$key]['campaign_type'] = $this->campaignType($rows[$key]);
            $rows[$key]['spend'] = (float) $row['spend'];
            $rows[$key]['impressions'] = (int) $row['impressions'];
            $rows[$key]['clicks'] = (int) $row['clicks'];
            $rows[$key]['platform_leads'] = (int) $row['platform_leads_rows'] > 0 ? (int) $row['platform_leads'] : null;
            $rows[$key]['platform_conversions'] = (float) $row['platform_conversions'];
            $rows[$key]['match_status'] = 'Sin leads Salesforce';
            $rows[$key]['campaign_source_type'] = 'platform_campaign';
        }

        foreach ($attributionRows as $row) {
            $key = $this->rowKey($row);
            $rows[$key] ??= $this->emptyCampaignRow($row);

            $rows[$key]['source_acquired'] = $row['source_acquired'];
            $rows[$key]['medium_acquired'] = $row['medium_acquired'];
            $rows[$key]['campaign_acquired'] = $row['campaign_acquired'];
            $rows[$key]['source_campaign_name'] = $row['source_campaign_name'] ?? $row['campaign_acquired'];
            $rows[$key]['acquired_id'] = $row['acquired_id'] ?? null;
            $rows[$key]['content_acquired'] = $row['content_acquired'] ?? null;
            $rows[$key]['campaign_source_type'] = $this->deriveSourceType($row);
            $rows[$key]['match_status'] = $row['match_status'] ?: ($rows[$key]['campaign_source_type'] === 'salesforce_origin' ? 'Procedencia Salesforce' : 'Sin inversion asociada');
            $rows[$key]['campaign_type'] = $row['campaign_type'] ?? $this->campaignType($rows[$key]);
            $rows[$key]['leads_salesforce'] = (int) $row['leads_salesforce'];
            $rows[$key]['opportunities'] = (int) $row['opportunities'];
            $rows[$key]['reservations'] = (int) $row['reservations'];
            $rows[$key]['live_reservations'] = (int) $row['live_reservations'];
            $rows[$key]['fallen_reservations'] = (int) $row['fallen_reservations'];
            $rows[$key]['sales'] = (int) $row['sales'];
            $rows[$key]['sale_amount'] = (int) $row['sale_amount_rows'] > 0 ? (float) $row['sale_amount'] : null;
            $rows[$key]['appraisals_generated'] = (int) $row['appraisals_generated'];
            $rows[$key]['purchases'] = (int) $row['purchases'];
            $rows[$key]['appraisal_amount'] = (int) $row['appraisal_amount_rows'] > 0 ? (float) $row['appraisal_amount'] : null;
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
            'source_campaign_name' => $source['source_campaign_name'] ?? ($source['campaign_acquired'] ?? null),
            'acquired_id' => $source['acquired_id'] ?? null,
            'content_acquired' => $source['content_acquired'] ?? null,
            'campaign_id' => $source['campaign_id'] ?? null,
            'campaign_name' => $source['campaign_name'] ?? null,
            'campaign_status' => $source['campaign_status'] ?? null,
            'campaign_effective_status' => $source['campaign_effective_status'] ?? null,
            'campaign_start_date' => $source['campaign_start_date'] ?? null,
            'campaign_end_date' => $source['campaign_end_date'] ?? null,
            'advertising_channel_type' => $source['advertising_channel_type'] ?? null,
            'advertising_channel_sub_type' => $source['advertising_channel_sub_type'] ?? null,
            'last_spend_date' => $source['last_spend_date'] ?? null,
            'campaign_type' => $this->campaignType($source),
            'campaign_status_label' => null,
            'match_status' => $source['match_status'] ?? null,
            'campaign_source_type' => $source['campaign_source_type'] ?? null,
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
            'appraisals_generated' => 0,
            'purchases' => 0,
            'appraisal_amount' => null,
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
            'cost_per_appraisal' => $this->divide($spend, $row['appraisals_generated'] ?? 0),
            'cost_per_purchase' => $this->divide($spend, $row['purchases'] ?? 0),
            'result_count' => $this->resultCountForRow($row),
            'cost_per_result' => $this->costPerResultForRow($row),
            'click_to_lead_salesforce' => $this->divide($row['leads_salesforce'], $row['clicks']),
            'click_to_lead_platform' => $row['platform_leads'] !== null ? $this->divide($row['platform_leads'], $row['clicks']) : null,
            'lead_to_opportunity' => $this->divide($row['opportunities'], $row['leads_salesforce']),
            'opportunity_to_reservation' => $this->divide($row['reservations'], $row['opportunities']),
            'reservation_to_sale' => $this->divide($row['sales'], $row['reservations']),
            'lead_to_sale' => $this->divide($row['sales'], $row['leads_salesforce']),
            'lead_to_purchase' => $this->divide($row['purchases'] ?? 0, $row['leads_salesforce']),
            'opportunity_to_purchase' => $this->divide($row['purchases'] ?? 0, $row['opportunities']),
        ]);
    }

    private function withDerivedState(array $row): array
    {
        $spend = (float) ($row['spend'] ?? 0);
        $leads = (int) ($row['leads_salesforce'] ?? 0);
        $status = $row['match_status'] ?? null;
        $sourceType = $this->deriveSourceType($row);

        if ($spend > 0 && $leads === 0) {
            $status = 'Sin leads Salesforce';
        } elseif ($sourceType === 'salesforce_origin') {
            $status = 'Procedencia Salesforce';
        } elseif ($leads > 0 && $spend <= 0.0) {
            $status = 'Sin inversion asociada';
        } elseif ($status === null && $leads > 0) {
            $status = 'Cruzada';
        }

        $row['match_status'] = $status ?? 'Sin datos';
        $row['campaign_source_type'] = $sourceType;
        $row['campaign_source_type_label'] = $this->sourceTypeLabel($sourceType);
        $row['display_campaign'] = $this->displayCampaign($row);

        return $row;
    }

    private function deriveSourceType(array|object $row): string
    {
        $sourceType = data_get($row, 'campaign_source_type');

        if (in_array($sourceType, ['platform_campaign', 'salesforce_campaign_without_spend', 'salesforce_origin'], true)) {
            return $sourceType;
        }

        if ((string) data_get($row, 'platform') !== 'salesforce') {
            return 'platform_campaign';
        }

        if ($this->normalizer->isValidAttributionValue(data_get($row, 'campaign_acquired'))) {
            return 'salesforce_campaign_without_spend';
        }

        return 'salesforce_origin';
    }

    private function sourceTypeLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'platform_campaign' => 'Campana plataforma',
            'salesforce_campaign_without_spend' => 'Campana Salesforce sin inversion',
            'salesforce_origin' => 'Procedencia Salesforce',
            default => 'Sin clasificar',
        };
    }

    private function includeCampaignForContext(array $row, array $filters): bool
    {
        if ($this->campaignTypeResolver->shouldExclude($row['source_campaign_name'] ?? ($row['campaign_name'] ?? null))) {
            return false;
        }

        $context = $filters['context'] ?? 'all';

        if (in_array($context, ['all', 'todas', '', null], true)) {
            return true;
        }

        return $this->campaignType($row) === $context;
    }

    private function campaignType(array $row): string
    {
        if (in_array(($row['campaign_type'] ?? null), ['venta', 'tasacion', 'exposicion', 'branding', 'otros'], true)) {
            return (string) $row['campaign_type'];
        }

        $sourceCampaignName = $row['source_campaign_name'] ?? $row['campaign_acquired'] ?? null;

        if (filled($sourceCampaignName)) {
            $sourceType = $this->campaignTypeResolver->sourceCampaignType($sourceCampaignName);

            if ($sourceType !== null) {
                return $sourceType;
            }
        }

        return $this->campaignTypeResolver->typeFor(
            $row['platform'] ?? null,
            $row['campaign_id'] ?? null,
            $row['campaign_name'] ?? null,
        );
    }

    private function campaignStatusLabel(array $row): string
    {
        return $this->campaignIsActive($row) ? 'Activa' : 'Inactiva';
    }

    private function campaignIsActive(array $row): bool
    {
        $platform = (string) ($row['platform'] ?? '');
        $status = mb_strtoupper((string) ($row['campaign_status'] ?? ''));
        $effectiveStatus = mb_strtoupper((string) ($row['campaign_effective_status'] ?? ''));

        if ($status === '' && $effectiveStatus === '' && (int) ($row['leads_salesforce'] ?? 0) > 0) {
            return true;
        }

        return match ($platform) {
            'google_ads' => $status === 'ENABLED',
            'meta' => $effectiveStatus === 'ACTIVE',
            default => false,
        };
    }

    private function recordTypeSql(): string
    {
        return "LOWER(COALESCE(so.record_type_name, ''))";
    }

    private function closedLostSql(): string
    {
        return "LOWER(COALESCE(so.stage_name, '')) = 'cerrada perdida'";
    }

    private function ventaOpportunitySql(): string
    {
        $recordType = $this->recordTypeSql();

        return "((so.salesforce_id IS NULL AND ca.has_opportunity = 1) OR (($recordType LIKE '%venta%') OR ($recordType LIKE '%cambio%' AND COALESCE(so.opo_for_importe_total, 0) > 0)))";
    }

    private function tasacionOpportunitySql(): string
    {
        $recordType = $this->recordTypeSql();

        return "(($recordType LIKE '%tasaci%') OR ($recordType LIKE '%cambio%' AND COALESCE(so.opo_for_importe_total, 0) < 0))";
    }

    private function ventaSignedSql(): string
    {
        $legacySigned = '(so.salesforce_id IS NULL AND ca.has_sale = 1)';
        $opportunitySigned = "(so.cv_signed = 1 AND NOT ({$this->closedLostSql()}) AND {$this->ventaOpportunitySql()})";

        return "({$legacySigned} OR {$opportunitySigned})";
    }

    private function tasacionSignedSql(): string
    {
        return "(so.cv_signed = 1 AND NOT ({$this->closedLostSql()}) AND {$this->tasacionOpportunitySql()})";
    }

    private function displayCampaign(array $row): string
    {
        if (($row['campaign_source_type'] ?? null) === 'salesforce_origin') {
            return implode(' · ', array_filter([
                $row['source_acquired'] ?? null,
                $row['medium_acquired'] ?? null,
            ])) ?: ($row['campaign_name'] ?? '-');
        }

        return $row['campaign_name'] ?: ($row['campaign_acquired'] ?: ($row['campaign_id'] ?: '-'));
    }

    private function mainCampaignRows(array $rows, array $filters): array
    {
        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->includeCampaignForContext($row, $filters)));

        if (filled($filters['campaign_status'] ?? null)) {
            $rows = array_values(array_filter($rows, function (array $row) use ($filters): bool {
                return $filters['campaign_status'] === 'active'
                    ? $this->campaignIsActive($row)
                    : ! $this->campaignIsActive($row);
            }));
        }

        if (filled($filters['classification'] ?? null)) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => ($row['classification'] ?? null) === $filters['classification']));
        }

        return $rows;
    }

    private function platformRows(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => in_array(($row['platform'] ?? null), ['google_ads', 'meta'], true)));
    }

    private function summaryFromRows(array $rows, array $allRows, array $filters, array $period): array
    {
        $charts = $this->charts($rows, $filters, $period);
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
            'appraisals_generated' => array_sum(array_column($rows, 'appraisals_generated')),
            'purchases' => array_sum(array_column($rows, 'purchases')),
            'appraisal_amount' => collect($rows)->contains(fn (array $row) => $row['appraisal_amount'] !== null)
                ? array_sum(array_map(fn (array $row) => (float) ($row['appraisal_amount'] ?? 0), $rows))
                : null,
        ]);
        $totals['result_count'] = $this->resultCountForRows($rows);
        $totals['cost_per_result'] = $this->divide((float) $totals['spend'], (int) $totals['result_count']);

        return [
            'ok' => count($rows) > 0,
            'selected_context' => $filters['context'],
            'selected_context_label' => $this->contextLabel($filters['context']),
            'period_mode' => 'lead_pivot',
            'periodo_actual' => [
                'inicio' => $period['start'],
                'fin' => $period['end'],
            ],
            'datos_actualizados' => $this->lastUpdated()?->toDateTimeString(),
            'kpis' => $totals,
            'warnings' => $this->warnings($rows, $totals, $period, $filters),
            'charts' => $charts,
            'daily_investment_leads' => $charts['daily_evolution'],
            'daily_results' => $charts['daily_reservations_sales'],
            'platform_comparison' => $charts['platforms'],
            'review_campaigns' => $this->reviewCampaigns($rows, $filters),
            'diagnostics' => $this->diagnostics($allRows, $period, $filters),
            'filters' => $this->filterOptions(),
        ];
    }

    private function rankingsFromRows(array $rows, array $filters): array
    {
        return [
            'top_spend' => $this->top(array_values(array_filter($rows, fn (array $row): bool => (float) ($row['spend'] ?? 0) > 0)), 'spend'),
            'top_impressions' => $this->top($rows, 'impressions'),
            'top_leads_salesforce' => $this->top($rows, 'leads_salesforce'),
            'top_opportunities' => $this->top($rows, 'opportunities'),
            'top_reservations' => $this->top($rows, 'reservations'),
            'top_sales' => $this->top($rows, 'sales'),
            'top_purchases' => $this->top($rows, 'purchases'),
            'top_sale_amount' => $this->top($rows, 'sale_amount', requireValue: true),
            'best_roas' => $this->top($rows, 'roas', requireValue: true),
            'best_ctr' => $this->top($rows, 'ctr', requireValue: true),
            'best_cpc' => $this->top($rows, 'cpc', ascending: true, requireValue: true),
            'best_cost_per_sale' => $this->top($rows, 'cost_per_sale', ascending: true, requireValue: true),
            'best_cost_per_purchase' => $this->top($rows, 'cost_per_purchase', ascending: true, requireValue: true),
            'best_cost_per_lead' => $this->top($rows, 'cost_per_lead', ascending: true, requireValue: true),
            'best_cost_per_opportunity' => $this->top($rows, 'cost_per_opportunity', ascending: true, requireValue: true),
            'best_cost_per_result' => $this->top($rows, 'cost_per_result', ascending: true, requireValue: true),
            'best_lead_to_purchase' => $this->top($rows, 'lead_to_purchase', requireValue: true),
            'worst_cost_per_sale' => $this->top($rows, 'cost_per_sale', requireValue: true),
            'high_spend_low_conversion' => collect($rows)->sortByDesc('spend')->sortBy('sales')->take(5)->values()->all(),
            'many_leads_few_sales' => collect($rows)->sortByDesc('leads_salesforce')->sortBy('sales')->take(5)->values()->all(),
            'many_leads_few_purchases' => collect($rows)->sortByDesc('leads_salesforce')->sortBy('purchases')->take(5)->values()->all(),
            'review_campaigns' => $this->reviewCampaigns($rows, $filters),
            'review_tracking' => $this->classification($rows, 'Revisar tracking'),
            'boost' => $this->classification($rows, 'Potenciar'),
            'review' => $this->classification($rows, 'Revisar'),
            'stop' => $this->classification($rows, 'Parar'),
        ];
    }

    private function charts(array $rows, array $filters, array $period): array
    {
        $monthlyEvolution = $this->monthlyEvolution($filters, $period);
        $dailyEvolution = $this->dailyEvolution($filters, $period);

        return [
            'monthly_evolution' => $monthlyEvolution,
            'daily_evolution' => $dailyEvolution,
            'daily_reservations_sales' => $this->dailyReservationsSales($filters, $period),
            'funnel' => $this->funnelChart($rows, $filters['context'] ?? 'venta'),
            'platforms' => $this->platformBars($rows),
        ];
    }

    private function monthlyEvolution(array $filters, array $period): array
    {
        $start = CarbonImmutable::parse($period['end_local'])->subMonthsNoOverflow(23)->startOfMonth()->startOfDay();
        $end = CarbonImmutable::parse($period['end_local'])->endOfDay();
        $startUtc = $start->utc();
        $endUtcExclusive = $end->addDay()->startOfDay()->utc();
        $months = [];
        $cursor = $start->startOfMonth();
        $leadKeysByMonth = [];
        $opportunityKeysByMonth = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m');
            $months[$key] = [
                'date' => $cursor->toDateString(),
                'label' => ucfirst($cursor->locale('es')->translatedFormat('F')),
                'spend' => 0.0,
                'impressions' => 0,
                'clicks' => 0,
                'leads_salesforce' => 0,
                'opportunities' => 0,
                'reservations' => 0,
                'sales' => 0,
                'appraisals_generated' => 0,
                'purchases' => 0,
            ];
            $cursor = $cursor->addMonthNoOverflow();
        }

        $metricQuery = DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $start->toDateString())
            ->where('metric_date', '<=', $end->toDateString());
        $this->applyMetricFilters($metricQuery, array_merge($filters, ['campaign_source_type' => 'platform_campaign']));

        $metricQuery
            ->select([
                DB::raw('SUBSTR(metric_date, 1, 7) as metric_month'),
                'platform',
                'campaign_id',
                'campaign_name',
                DB::raw('SUM(COALESCE(spend, 0)) as spend'),
                DB::raw('SUM(COALESCE(impressions, 0)) as impressions'),
                DB::raw('SUM(COALESCE(clicks, 0)) as clicks'),
            ])
            ->groupBy(DB::raw('SUBSTR(metric_date, 1, 7)'), 'platform', 'campaign_id', 'campaign_name')
            ->get()
            ->each(function (object $row) use (&$months, $filters): void {
                $campaign = (array) $row;

                if (! $this->includeCampaignForContext($campaign, $filters)) {
                    return;
                }

                $month = (string) $row->metric_month;

                if (! isset($months[$month])) {
                    return;
                }

                $months[$month]['spend'] = round($months[$month]['spend'] + (float) $row->spend, 2);
                $months[$month]['impressions'] += (int) $row->impressions;
                $months[$month]['clicks'] += (int) $row->clicks;
            });

        $attributionQuery = DB::table('campaign_lead_attributions as cla')
            ->where('cla.lead_created_date', '>=', $startUtc)
            ->where('cla.lead_created_date', '<', $endUtcExclusive);

        $this->applyLeadAttributionFilters($attributionQuery, $filters, 'cla');

        if (filled($filters['campaign_source_type'] ?? null)) {
            if ($filters['campaign_source_type'] === 'platform_campaign') {
                $attributionQuery->where('cla.platform', '<>', 'salesforce');
            } else {
                $attributionQuery->where('cla.platform', 'salesforce');
            }
        }

        if (! in_array($filters['context'], ['all', 'todas', '', null], true)) {
            $attributionQuery->where('cla.campaign_type', $filters['context']);
        }

        $attributionQuery
            ->select([
                'cla.lead_id',
                'cla.lead_created_date',
                'cla.platform',
                'cla.campaign_id',
                'cla.campaign_name',
                'cla.opportunity_id',
                'cla.has_opportunity',
                'cla.has_reservation',
                'cla.has_sale',
                'cla.has_purchase',
                'cla.campaign_type',
            ])
            ->get()
            ->each(function (object $row) use (&$months, $filters, &$opportunityKeysByMonth): void {
                $campaign = [
                    'platform' => $row->platform,
                    'campaign_id' => $row->campaign_id,
                    'campaign_name' => $row->campaign_name,
                ];

                if (! $this->includeCampaignForContext($campaign, $filters)) {
                    return;
                }

                $month = $this->reportDateTime($row->lead_created_date)?->format('Y-m');

                if ($month === null || ! isset($months[$month])) {
                    return;
                }

                $leadKey = $month.'|'.$row->lead_id;

                if (! isset($leadKeysByMonth[$leadKey])) {
                    $months[$month]['leads_salesforce']++;
                    $leadKeysByMonth[$leadKey] = true;
                }

                if ((bool) $row->has_opportunity && filled($row->opportunity_id)) {
                    $opportunityKey = $month.'|'.$row->opportunity_id;

                    if (! isset($opportunityKeysByMonth[$opportunityKey])) {
                        $months[$month]['opportunities']++;
                        $opportunityKeysByMonth[$opportunityKey] = true;
                    }
                }

                $months[$month]['reservations'] += (int) ((bool) $row->has_reservation);
                $months[$month]['sales'] += (int) ((bool) $row->has_sale);
                $months[$month]['appraisals_generated'] += (int) ((bool) $row->has_opportunity && $row->campaign_type === 'tasacion');
                $months[$month]['purchases'] += (int) ((bool) $row->has_purchase);
            });

        return array_values($months);
    }

    private function dailyEvolution(array $filters, array $period): array
    {
        if (filled($filters['campaign_source_type'] ?? null) && $filters['campaign_source_type'] !== 'platform_campaign') {
            return [];
        }

        $metricQuery = DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $period['start'])
            ->where('metric_date', '<=', $period['end']);
        $this->applyMetricFilters($metricQuery, array_merge($filters, ['campaign_source_type' => 'platform_campaign']));

        $spendByDate = $metricQuery
            ->select('metric_date', DB::raw('SUM(COALESCE(spend, 0)) as spend'))
            ->groupBy('metric_date')
            ->pluck('spend', 'metric_date');

        $attributionQuery = DB::table('campaign_lead_attributions as cla')
            ->where('cla.lead_created_date', '>=', $period['start_at'])
            ->where('cla.lead_created_date', '<', $period['end_at']);

        $this->applyLeadAttributionFilters($attributionQuery, $filters, 'cla');

        if (filled($filters['campaign_source_type'] ?? null)) {
            if ($filters['campaign_source_type'] === 'platform_campaign') {
                $attributionQuery->where('cla.platform', '<>', 'salesforce');
            } else {
                $attributionQuery->where('cla.platform', 'salesforce');
            }
        }

        if (! in_array($filters['context'], ['all', 'todas', '', null], true)) {
            $attributionQuery->where('cla.campaign_type', $filters['context']);
        }

        $attributionByDate = $attributionQuery
            ->select([
                'cla.lead_id',
                'cla.lead_created_date',
            ])
            ->get()
            ->reduce(function (array $carry, object $row): array {
                $metricDate = $this->reportDateTime($row->lead_created_date)?->toDateString();

                if ($metricDate === null) {
                    return $carry;
                }

                $carry[$metricDate] ??= [];
                $carry[$metricDate][(string) $row->lead_id] = true;

                return $carry;
            }, []);

        $rows = [];
        $cursor = CarbonImmutable::parse($period['start'])->startOfDay();
        $end = CarbonImmutable::parse($period['end'])->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $rows[] = [
                'date' => $date,
                'spend' => round((float) ($spendByDate[$date] ?? 0), 2),
                'leads_salesforce' => isset($attributionByDate[$date]) ? count($attributionByDate[$date]) : 0,
            ];
            $cursor = $cursor->addDay();
        }

        return $rows;
    }

    private function dailyReservationsSales(array $filters, array $period): array
    {
        $query = DB::table('campaign_lead_attributions as cla')
            ->where('cla.lead_created_date', '>=', $period['start_at'])
            ->where('cla.lead_created_date', '<', $period['end_at']);

        $this->applyLeadAttributionFilters($query, $filters, 'cla');

        if (filled($filters['campaign_source_type'] ?? null)) {
            if ($filters['campaign_source_type'] === 'platform_campaign') {
                $query->where('cla.platform', '<>', 'salesforce');
            } else {
                $query->where('cla.platform', 'salesforce');
            }
        }

        if (! in_array($filters['context'], ['all', 'todas', '', null], true)) {
            $query->where('cla.campaign_type', $filters['context']);
        }

        $rowsByDate = (clone $query)
            ->select([
                'cla.lead_created_date',
                'cla.has_reservation',
                'cla.has_sale',
            ])
            ->get()
            ->reduce(function (array $carry, object $row): array {
                $metricDate = $this->reportDateTime($row->lead_created_date)?->toDateString();

                if ($metricDate === null) {
                    return $carry;
                }

                $carry[$metricDate]['reservations'] = ($carry[$metricDate]['reservations'] ?? 0) + (int) ((bool) $row->has_reservation);
                $carry[$metricDate]['sales'] = ($carry[$metricDate]['sales'] ?? 0) + (int) ((bool) $row->has_sale);

                return $carry;
            }, []);

        $rows = [];
        $cursor = CarbonImmutable::parse($period['start'])->startOfDay();
        $end = CarbonImmutable::parse($period['end'])->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $rows[] = [
                'date' => $date,
                'reservations' => (int) ($rowsByDate[$date]['reservations'] ?? 0),
                'sales' => (int) ($rowsByDate[$date]['sales'] ?? 0),
            ];
            $cursor = $cursor->addDay();
        }

        return $rows;
    }

    private function funnelChart(array $rows, string $context = 'venta'): array
    {
        $rows = array_values($rows);
        $base = [
            ['label' => 'Impresiones', 'value' => array_sum(array_column($rows, 'impressions'))],
            ['label' => 'Clicks', 'value' => array_sum(array_column($rows, 'clicks'))],
            ['label' => 'Leads Salesforce', 'value' => array_sum(array_column($rows, 'leads_salesforce'))],
        ];

        $steps = match ($context) {
            'tasacion' => [
                ['label' => 'Oportunidades', 'value' => array_sum(array_column($rows, 'opportunities'))],
                ['label' => 'Compras', 'value' => array_sum(array_column($rows, 'purchases'))],
            ],
            'exposicion' => [
                ['label' => 'Oportunidades', 'value' => array_sum(array_column($rows, 'opportunities'))],
            ],
            'branding' => [
                ['label' => 'Oportunidades', 'value' => array_sum(array_column($rows, 'opportunities'))],
            ],
            default => [
                ['label' => 'Oportunidades', 'value' => array_sum(array_column($rows, 'opportunities'))],
                ['label' => 'Reservas', 'value' => array_sum(array_column($rows, 'reservations'))],
                ['label' => 'Ventas', 'value' => array_sum(array_column($rows, 'sales'))],
            ],
        };

        if (in_array($context, ['all', 'todas', '', null, 'otros'], true)) {
            $steps = [
                ['label' => 'Oportunidades', 'value' => array_sum(array_column($rows, 'opportunities'))],
                ['label' => 'Resultados', 'value' => array_sum(array_map(fn (array $row) => $this->resultCountForRow($row), $rows))],
            ];
        }

        return array_merge($base, $steps);
    }

    private function platformBars(array $rows): array
    {
        return collect($rows)
            ->filter(fn (array $row): bool => in_array(($row['platform'] ?? null), ['google_ads', 'meta'], true))
            ->groupBy('platform')
            ->map(function ($items, string $platform): array {
                $spend = round((float) $items->sum('spend'), 2);
                $leads = (int) $items->sum('leads_salesforce');
                $opportunities = (int) $items->sum('opportunities');
                $purchases = (int) $items->sum('purchases');
                $sales = (int) $items->sum('sales');
                $resultCount = $this->resultCountForRows($items->all());
                $resultLabel = $this->resultLabelForContext($this->contextForRows($items->all()));

                return [
                    'platform' => $platform,
                    'spend' => $spend,
                    'impressions' => (int) $items->sum('impressions'),
                    'clicks' => (int) $items->sum('clicks'),
                    'leads_salesforce' => $leads,
                    'opportunities' => $opportunities,
                    'reservations' => (int) $items->sum('reservations'),
                    'sales' => $sales,
                    'purchases' => $purchases,
                    'result_label' => $resultLabel,
                    'result_count' => $resultCount,
                    'cost_per_lead' => $this->divide($spend, $leads),
                    'cost_per_opportunity' => $this->divide($spend, $opportunities),
                    'cost_per_purchase' => $this->divide($spend, $purchases),
                    'cost_per_sale' => $this->divide($spend, $sales),
                    'cost_per_result' => $this->divide($spend, $resultCount),
                    'lead_to_purchase' => $this->divide($purchases, $leads),
                ];
            })
            ->sortByDesc('spend')
            ->values()
            ->all();
    }

    private function reviewCampaigns(array $rows, array $filters): array
    {
        $rows = array_values($rows);
        $avgCostByType = collect($rows)
            ->groupBy(fn (array $row) => $this->campaignType($row))
            ->map(function ($items): ?float {
                $costs = $items
                    ->map(fn (array $row) => $this->costPerResultForRow($row))
                    ->filter(fn ($value) => $value !== null && $value > 0);

                return $costs->isNotEmpty() ? round((float) $costs->avg(), 4) : null;
            })
            ->all();

        return collect($rows)
            ->map(function (array $row) use ($avgCostByType): ?array {
                $spend = (float) ($row['spend'] ?? 0);
                $clicks = (int) ($row['clicks'] ?? 0);
                $leads = (int) ($row['leads_salesforce'] ?? 0);
                $opportunities = (int) ($row['opportunities'] ?? 0);
                $resultCount = $this->resultCountForRow($row);
                $costPerResult = $this->costPerResultForRow($row);
                $type = $this->campaignType($row);
                $avgCost = $avgCostByType[$type] ?? null;

                if ($spend > 100 && $leads === 0) {
                    return $this->reviewRow($row, 'Revisar tracking', 'inversion superior a 100 € y cero leads', 'Leads Salesforce', $leads, $costPerResult, $resultCount);
                }

                if ($clicks > 300 && $leads <= 1) {
                    return $this->reviewRow($row, 'Revisar conversion', 'mas de 300 clicks y leads bajos o nulos', 'Leads Salesforce', $leads, $costPerResult, $resultCount);
                }

                if ($leads > 0 && $opportunities === 0) {
                    return $this->reviewRow($row, 'Revisar calidad', 'hay leads Salesforce pero no oportunidades', 'Oportunidades', $opportunities, $costPerResult, $resultCount);
                }

                if ($opportunities > 0 && $resultCount === 0) {
                    return $this->reviewRow($row, 'Revisar cierre', 'hay oportunidades pero no hay resultado final', 'Resultado', $resultCount, $costPerResult, $resultCount);
                }

                if ($avgCost !== null && $costPerResult !== null && $costPerResult > ($avgCost * 2)) {
                    return $this->reviewRow($row, 'Coste alto', 'coste por resultado por encima del doble de la media del tipo', 'Coste por resultado', $costPerResult, $costPerResult, $resultCount);
                }

                if (! $this->campaignIsActive($row) && ($spend > 0 || $leads > 0 || $resultCount > 0)) {
                    return $this->reviewRow($row, 'Revisar estado', 'campana inactiva con inversion o resultados recientes', 'Resultado', $resultCount ?: $leads, $costPerResult, $resultCount);
                }

                return null;
            })
            ->filter()
            ->sortByDesc(fn (array $row) => $row['value'] ?? 0)
            ->take(8)
            ->values()
            ->all();
    }

    private function reviewRow(array $row, string $reason, string $detail, string $metric, float|int|null $value, float|int|null $costPerResult = null, ?int $resultCount = null): array
    {
        return [
            'campaign' => $this->displayCampaign($row),
            'campaign_name' => $row['campaign_name'] ?? null,
            'campaign_id' => $row['campaign_id'] ?? null,
            'platform' => $row['platform'] ?? null,
            'campaign_type' => $row['campaign_type'] ?? null,
            'reason' => $reason,
            'detail' => $detail,
            'metric' => $metric,
            'value' => $value,
            'cost_per_result' => $costPerResult,
            'result_count' => $resultCount,
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

    private function warnings(array $rows, array $totals, array $period, array $filters): array
    {
        $warnings = [];

        if (! filled(config('services.meta_ads.access_token')) || config('services.meta_ads.ad_account_ids') === []) {
            $warnings[] = 'Las credenciales de Meta Ads no estan configuradas. Se muestran datos disponibles de Salesforce y/o ultima inversion cacheada.';
        }

        if (! filled(config('services.google_ads.developer_token')) || config('services.google_ads.customer_ids') === []) {
            $warnings[] = 'Las credenciales de Google Ads no estan configuradas. Se muestran datos disponibles de Salesforce y/o ultima inversion cacheada.';
        }

        if (($totals['leads_salesforce'] ?? 0) > 0 && (float) ($totals['spend'] ?? 0) <= 0.0) {
            $warnings[] = 'Hay campanas Salesforce sin inversion asociada o procedencias sin coste. Revisar IDs/nombres de campana.';
        }

        if ((float) ($totals['spend'] ?? 0) > 0.0 && (int) ($totals['leads_salesforce'] ?? 0) === 0) {
            $warnings[] = 'Hay inversion de plataforma sin leads Salesforce asociados.';
        }

        if (CampaignAttribution::query()->count() === 0) {
            $warnings[] = 'No hay atribuciones construidas para el periodo seleccionado.';
        }

        if (($totals['sales'] ?? 0) > 0 && ($totals['sale_amount'] ?? null) === null) {
            $warning = $this->saleAmountWarning($period, $filters);

            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        $lastSyncedAt = $this->lastMetricSyncedAt();
        if ($lastSyncedAt === null) {
            $warnings[] = 'No hay inversion sincronizada todavia en campaign_platform_daily_metrics.';
        } elseif ($lastSyncedAt->lessThan(now()->subHours(36))) {
            $warnings[] = 'Los datos de inversion no se han actualizado en las ultimas 36 horas.';
        }

        return array_values(array_unique($warnings));
    }

    private function saleAmountWarning(array $period, array $filters): ?string
    {
        if (! $this->saleAmountResolver->preferredColumnExists()) {
            return $this->saleAmountResolver->diagnosticMessage().' ROAS y ROI quedan sin dato hasta que exista ese campo local.';
        }

        $attributedSales = $this->attributionBase($period, $filters)
            ->where('has_sale', true)
            ->count();

        if ($attributedSales === 0) {
            return null;
        }

        $attributedSalesWithAmount = $this->attributedSalesWithResolvedAmount($period, $filters, 'count');

        if ($attributedSalesWithAmount > 0) {
            return null;
        }

        $salesWithLocalOpportunity = $this->attributionBase($period, $filters)
            ->join('salesforce_opportunities as so', 'so.salesforce_id', '=', 'campaign_lead_attributions.opportunity_id')
            ->where('campaign_lead_attributions.has_sale', true)
            ->count();

        if ($salesWithLocalOpportunity === 0) {
            return 'Hay ventas atribuidas sin oportunidad local asociada. Revisar cruce opportunity_id.';
        }

        $synchronizedPositiveAmounts = DB::table('salesforce_opportunities')
            ->where('opo_for_importe_total', '>', 0)
            ->count();

        if ($synchronizedPositiveAmounts === 0) {
            return 'La columna opo_for_importe_total existe, pero no contiene importes sincronizados. Revisar sync de Opportunity.OPO_FOR_Importe_total__c.';
        }

        if ($this->attributedSalesWithOpportunityAmount($period, $filters, 'opo_for_importe_total', 'count') > 0) {
            return null;
        }

        return $this->saleAmountResolver->emptyAmountsMessage();
    }

    private function attributionBase(array $period, array $filters)
    {
        return DB::table('campaign_lead_attributions')
            ->where('lead_created_date', '>=', $period['start_at'])
            ->where('lead_created_date', '<', $period['end_at']);
    }

    private function diagnostics(array $rows, array $period, array $filters): array
    {
        $attributionBase = DB::table('campaign_lead_attributions')
            ->where('lead_created_date', '>=', $period['start_at'])
            ->where('lead_created_date', '<', $period['end_at']);
        $legacyAttributionBase = DB::table('campaign_attributions')
            ->where('lead_created_at', '>=', $period['start_at'])
            ->where('lead_created_at', '<', $period['end_at']);
        $rowCollection = collect($rows);

        return [
            'last_meta_sync' => DB::table('campaign_platform_daily_metrics')->where('platform', 'meta')->max('synced_at'),
            'last_google_sync' => DB::table('campaign_platform_daily_metrics')->where('platform', 'google_ads')->max('synced_at'),
            'last_attribution_build' => DB::table('campaign_lead_attributions')->max('updated_at'),
            'meta_metric_rows' => DB::table('campaign_platform_daily_metrics')->where('platform', 'meta')->count(),
            'google_metric_rows' => DB::table('campaign_platform_daily_metrics')->where('platform', 'google_ads')->count(),
            'salesforce_leads_with_campaign_period' => $this->leadsWithAcquisitionNotNull($period),
            'valid_candidate_leads' => (clone $attributionBase)->count(),
            'built_attributions' => (clone $attributionBase)->count(),
            'campaigns_spend_without_salesforce_leads' => $rowCollection->filter(fn (array $row) => (float) $row['spend'] > 0 && (int) $row['leads_salesforce'] === 0)->count(),
            'campaigns_salesforce_without_spend' => $rowCollection->filter(fn (array $row) => (int) $row['leads_salesforce'] > 0 && (float) $row['spend'] <= 0.0)->count(),
            'campaigns_matched_by_id' => $rowCollection->filter(fn (array $row) => str_contains((string) ($row['match_status'] ?? ''), 'ID'))->count(),
            'campaigns_matched_by_name' => $rowCollection->filter(fn (array $row) => str_contains((string) ($row['match_status'] ?? ''), 'nombre'))->count(),
            'platform_campaigns' => $rowCollection->where('campaign_source_type', 'platform_campaign')->count(),
            'salesforce_origins' => (clone $legacyAttributionBase)->where('campaign_source_type', 'salesforce_origin')->count(),
            'salesforce_campaigns_without_spend' => (clone $legacyAttributionBase)->where('campaign_source_type', 'salesforce_campaign_without_spend')->count(),
            'crossed_campaigns' => $rowCollection->filter(fn (array $row) => in_array($row['match_status'] ?? null, ['Cruzada por ID', 'Cruzada por nombre'], true))->count(),
            'campaigns_without_crossing' => $rowCollection->filter(fn (array $row) => ! in_array($row['match_status'] ?? null, ['Cruzada por ID', 'Cruzada por nombre'], true))->count(),
            'leads_platform_campaigns' => $rowCollection->where('campaign_source_type', 'platform_campaign')->sum('leads_salesforce'),
            'leads_salesforce_origins' => (clone $legacyAttributionBase)->where('campaign_source_type', 'salesforce_origin')->count(),
            'attributed_sales' => $rowCollection->sum('sales'),
            'sales_with_amount_available' => $rowCollection->filter(fn (array $row) => (int) ($row['sales'] ?? 0) > 0 && ($row['sale_amount'] ?? null) !== null)->sum('sales'),
            'opportunities_cv_signed' => DB::table('salesforce_opportunities')->where('cv_signed', true)->count(),
            'attributed_sales_with_amount' => $this->attributedSalesWithResolvedAmount($period, $filters, 'count'),
            'attributed_sales_amount_sum' => $this->attributedSalesWithResolvedAmount($period, $filters, 'sum'),
            'sales_with_opo_for_importe_total' => $this->attributedSalesWithOpportunityAmount($period, $filters, 'opo_for_importe_total', 'count'),
            'sum_opo_for_importe_total_sales' => $this->attributedSalesWithOpportunityAmount($period, $filters, 'opo_for_importe_total', 'sum'),
            'sales_with_amount' => $this->attributedSalesWithOpportunityAmount($period, $filters, 'amount', 'count'),
            'sum_amount_sales' => $this->attributedSalesWithOpportunityAmount($period, $filters, 'amount', 'sum'),
            'amount_field_status' => $this->opportunityAmountFieldStatus('opo_for_importe_total'),
            'opo_for_importe_total_field_status' => $this->opportunityAmountFieldStatus('opo_for_importe_total'),
            'amount_fallback_field_status' => $this->opportunityAmountFieldStatus('amount'),
            'sale_amount_field_used' => $this->saleAmountFieldUsed($period, $filters),
            'candidates_with_campaign_acquired' => (clone $attributionBase)->whereNotNull('campaign_acquired')->where('campaign_acquired', '<>', '')->count(),
            'candidates_only_source_medium' => (clone $attributionBase)
                ->where('platform', 'salesforce')
                ->count(),
            'candidates_with_acquired_id' => (clone $attributionBase)->whereNotNull('acquired_id')->where('acquired_id', '<>', '')->count(),
            'candidates_with_content_acquired' => (clone $attributionBase)->whereNotNull('content_acquired')->where('content_acquired', '<>', '')->count(),
            'match_ad_id' => (clone $legacyAttributionBase)->where('attribution_method', 'ad_id_match')->count(),
            'match_adset_or_adgroup' => (clone $legacyAttributionBase)->where('attribution_method', 'adset_or_adgroup_id_match')->count(),
            'match_campaign_id' => (clone $legacyAttributionBase)->where('attribution_method', 'campaign_id_match')->count(),
            'match_campaign_name_exact' => (clone $legacyAttributionBase)->where('attribution_method', 'campaign_name_match')->where('attribution_confidence', 'medium')->count(),
            'match_campaign_name_flexible' => (clone $legacyAttributionBase)->where('attribution_method', 'campaign_name_match')->where('attribution_confidence', 'low')->count(),
            'salesforce_only_by_campaign' => (clone $legacyAttributionBase)->where('campaign_source_type', 'salesforce_campaign_without_spend')->count(),
            'salesforce_only_by_origin' => (clone $legacyAttributionBase)->where('campaign_source_type', 'salesforce_origin')->count(),
        ];
    }

    private function opportunityAmountFieldStatus(string $column): string
    {
        if (! Schema::hasColumn('salesforce_opportunities', $column)) {
            return 'missing';
        }

        $cvSigned = DB::table('salesforce_opportunities')->where('cv_signed', true);
        $cvSignedCount = (clone $cvSigned)->count();
        $cvSignedWithAmount = (clone $cvSigned)->where($column, '>', 0)->count();

        if ($cvSignedCount > 0 && $cvSignedWithAmount === 0) {
            return 'exists_but_zero';
        }

        return 'exists_with_values';
    }

    private function attributedSalesWithOpportunityAmount(array $period, array $filters, string $column, string $aggregate): int|float
    {
        if (! Schema::hasColumn('salesforce_opportunities', $column)) {
            return $aggregate === 'sum' ? 0.0 : 0;
        }

        $query = DB::table('campaign_lead_attributions as cla')
            ->join('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->where('cla.lead_created_date', '>=', $period['start_at'])
            ->where('cla.lead_created_date', '<', $period['end_at'])
            ->where('cla.has_sale', true)
            ->where("so.{$column}", '>', 0);

        return $aggregate === 'sum'
            ? round((float) $query->sum("so.{$column}"), 2)
            : $query->count();
    }

    private function attributedSalesWithResolvedAmount(array $period, array $filters, string $aggregate): int|float
    {
        $query = DB::table('campaign_lead_attributions as cla')
            ->leftJoin('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->where('cla.lead_created_date', '>=', $period['start_at'])
            ->where('cla.lead_created_date', '<', $period['end_at'])
            ->where('cla.has_sale', true);

        $fallbackSql = 'NULL';

        if (Schema::hasColumn('salesforce_opportunities', 'opo_for_importe_total')) {
            $fallbackSql = 'CASE WHEN COALESCE(so.opo_for_importe_total, 0) > 0 THEN so.opo_for_importe_total ELSE NULL END';
        }

        if (Schema::hasColumn('salesforce_opportunities', 'amount')) {
            $fallbackSql = $fallbackSql === 'NULL'
                ? 'CASE WHEN COALESCE(so.amount, 0) > 0 THEN so.amount ELSE NULL END'
                : "COALESCE({$fallbackSql}, CASE WHEN COALESCE(so.amount, 0) > 0 THEN so.amount ELSE NULL END)";
        }

        $resolvedAmountSql = "COALESCE(cla.sold_amount, {$fallbackSql})";

        if ($aggregate === 'sum') {
            return round((float) ($query->whereRaw("{$resolvedAmountSql} > 0")->sum(DB::raw($resolvedAmountSql)) ?? 0), 2);
        }

        return (clone $query)->whereRaw("{$resolvedAmountSql} > 0")->count();
    }

    private function saleAmountFieldUsed(array $period, array $filters): string
    {
        if ($this->attributedSalesWithOpportunityAmount($period, $filters, 'opo_for_importe_total', 'count') > 0) {
            return 'opo_for_importe_total';
        }

        if ($this->attributedSalesWithOpportunityAmount($period, $filters, 'amount', 'count') > 0) {
            return 'amount';
        }

        return 'none';
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
        if (filled($filters['campaign_source_type'] ?? null) && $filters['campaign_source_type'] !== 'platform_campaign') {
            $query->whereRaw('1 = 0');
        }

        foreach (['platform', 'account_id', 'campaign_id'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        $campaignNames = $filters['campaign_name'] ?? [];

        if (is_array($campaignNames) && count($campaignNames) > 0) {
            $query->whereIn('campaign_name', $campaignNames);
        } elseif (filled($campaignNames ?? null)) {
            $query->where('campaign_name', $campaignNames);
        }

        if (filled($filters['campaign_status'] ?? null)) {
            $query->where(function ($subQuery) use ($filters): void {
                if ($filters['campaign_status'] === 'active') {
                    $subQuery
                        ->where(function ($active): void {
                            $active->where('platform', 'google_ads')->where('campaign_status', 'ENABLED');
                        })
                        ->orWhere(function ($active): void {
                            $active->where('platform', 'meta')->where('campaign_effective_status', 'ACTIVE');
                        });

                    return;
                }

                $subQuery
                    ->where(function ($inactive): void {
                        $inactive->where('platform', 'google_ads')->where(function ($status): void {
                            $status->whereNull('campaign_status')->orWhere('campaign_status', '<>', 'ENABLED');
                        });
                    })
                    ->orWhere(function ($inactive): void {
                        $inactive->where('platform', 'meta')->where(function ($status): void {
                            $status->whereNull('campaign_effective_status')->orWhere('campaign_effective_status', '<>', 'ACTIVE');
                        });
                    });
            });
        }

        if (filled($filters['search'] ?? null)) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($subQuery) use ($search): void {
                $subQuery
                    ->where('campaign_id', 'like', $search)
                    ->orWhere('campaign_name', 'like', $search);
            });
        }
    }

    private function applyAttributionFilters($query, array $filters, ?string $alias = null): void
    {
        $column = fn (string $field): string => $alias ? "{$alias}.{$field}" : $field;

        foreach ([
            'platform',
            'account_id',
            'campaign_source_type',
            'source_acquired',
            'medium_acquired',
            'campaign_acquired',
            'campaign_id',
            'lead_status',
            'vehicle_interest',
        ] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($column($field), $filters[$field]);
            }
        }

        $campaignNames = $filters['campaign_name'] ?? [];

        if (is_array($campaignNames) && count($campaignNames) > 0) {
            $query->whereIn($column('campaign_name'), $campaignNames);
        } elseif (filled($campaignNames ?? null)) {
            $query->where($column('campaign_name'), $campaignNames);
        }

        if (filled($filters['delegation'] ?? null)) {
            $query->where($column('lead_delegation'), $filters['delegation']);
        }

        if (filled($filters['zone'] ?? null)) {
            $query->where($column('lead_zone'), $filters['zone']);
        }

        if (filled($filters['commercial_user'] ?? null)) {
            $query->where(function ($subQuery) use ($filters, $column): void {
                $subQuery
                    ->where($column('commercial_user_id'), $filters['commercial_user'])
                    ->orWhere($column('commercial_user_name'), $filters['commercial_user']);
            });
        }

        if (filled($filters['search'] ?? null)) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($subQuery) use ($search, $column): void {
                $subQuery
                    ->where($column('campaign_id'), 'like', $search)
                    ->orWhere($column('campaign_name'), 'like', $search)
                    ->orWhere($column('campaign_acquired'), 'like', $search)
                    ->orWhere($column('source_acquired'), 'like', $search)
                    ->orWhere($column('medium_acquired'), 'like', $search);
            });
        }

        foreach ([
            'has_opportunity' => 'has_opportunity',
            'has_reservation' => 'has_reservation',
            'has_sale' => 'has_sale',
        ] as $filter => $field) {
            $boolean = $this->booleanFilter($filters[$filter] ?? null);

            if ($boolean !== null) {
                $query->where($column($field), $boolean);
            }
        }
    }

    private function applyLeadAttributionFilters($query, array $filters, ?string $alias = null): void
    {
        $column = fn (string $field): string => $alias ? "{$alias}.{$field}" : $field;

        foreach ([
            'platform',
            'campaign_id',
            'campaign_name',
            'source_acquired',
            'medium_acquired',
            'campaign_acquired',
            'lead_status',
            'vehicle_interest',
        ] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($column($field), $filters[$field]);
            }
        }

        if (filled($filters['delegation'] ?? null)) {
            $query->where($column('lead_delegation'), $filters['delegation']);
        }

        if (filled($filters['zone'] ?? null)) {
            $query->where($column('lead_zone'), $filters['zone']);
        }

        if (filled($filters['commercial_user'] ?? null)) {
            $query->where(function ($subQuery) use ($filters, $column): void {
                $subQuery
                    ->where($column('commercial_user_id'), $filters['commercial_user'])
                    ->orWhere($column('commercial_user_name'), $filters['commercial_user']);
            });
        }

        if (filled($filters['search'] ?? null)) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($subQuery) use ($search, $column): void {
                $subQuery
                    ->where($column('campaign_id'), 'like', $search)
                    ->orWhere($column('campaign_name'), 'like', $search)
                    ->orWhere($column('source_campaign_name'), 'like', $search);
            });
        }

        foreach ([
            'has_opportunity' => 'has_opportunity',
            'has_reservation' => 'has_reservation',
            'has_sale' => 'has_sale',
            'has_purchase' => 'has_purchase',
        ] as $filter => $field) {
            $boolean = $this->booleanFilter($filters[$filter] ?? null);

            if ($boolean !== null) {
                $query->where($column($field), $boolean);
            }
        }
    }

    private function filterOptions(): array
    {
        return [
            'campaign_types' => [
                ['value' => 'all', 'label' => 'Todos'],
                ['value' => 'venta', 'label' => 'Venta'],
                ['value' => 'tasacion', 'label' => 'Tasacion'],
                ['value' => 'exposicion', 'label' => 'Exposicion'],
                ['value' => 'branding', 'label' => 'Branding'],
                ['value' => 'otros', 'label' => 'Otros'],
            ],
            'platforms' => collect($this->distinctFromBoth('platform'))
                ->filter(fn (string $platform): bool => in_array($platform, ['google_ads', 'meta'], true))
                ->values()
                ->all(),
            'source_types' => [
                ['value' => 'platform_campaign', 'label' => 'Campana plataforma'],
                ['value' => 'salesforce_campaign_without_spend', 'label' => 'Campana Salesforce sin inversion'],
            ],
            'classifications' => ['Potenciar', 'Revisar', 'Parar', 'Revisar tracking', 'Sin datos suficientes'],
            'campaign_statuses' => [
                ['value' => 'active', 'label' => 'Activas'],
                ['value' => 'inactive', 'label' => 'Inactivas'],
            ],
            'accounts' => DB::table('campaign_platform_daily_metrics')
                ->select('account_id', DB::raw('MIN(account_name) as account_name'))
                ->whereNotNull('account_id')
                ->groupBy('account_id')
                ->orderBy('account_id')
                ->get()
                ->map(fn ($row) => ['id' => $row->account_id, 'name' => $row->account_name])
                ->all(),
            'sources' => $this->distinct('campaign_lead_attributions', 'source_acquired'),
            'mediums' => $this->distinct('campaign_lead_attributions', 'medium_acquired'),
            'campaigns_acquired' => $this->distinct('campaign_lead_attributions', 'campaign_acquired'),
            'campaign_ids' => $this->distinctFromBoth('campaign_id'),
            'campaign_names' => $this->distinctFromBoth('campaign_name'),
            'delegations' => $this->distinct('campaign_lead_attributions', 'lead_delegation'),
            'zones' => $this->distinct('campaign_lead_attributions', 'lead_zone'),
            'lead_statuses' => $this->distinct('campaign_lead_attributions', 'lead_status'),
            'commercials' => DB::table('campaign_lead_attributions')
                ->select('commercial_user_id', 'commercial_user_name')
                ->whereNotNull('commercial_user_name')
                ->distinct()
                ->orderBy('commercial_user_name')
                ->get()
                ->map(fn ($row) => ['id' => $row->commercial_user_id ?: $row->commercial_user_name, 'name' => $row->commercial_user_name])
                ->all(),
            'vehicles' => $this->distinct('campaign_lead_attributions', 'vehicle_interest'),
            'months' => $this->monthOptions(),
        ];
    }

    private function normalizeContext(mixed $context): string
    {
        $context = $this->normalizer->key($context);

        return in_array($context, ['venta', 'tasacion', 'exposicion', 'branding', 'otros', 'all', 'todas'], true)
            ? ($context === 'todas' ? 'all' : $context)
            : 'all';
    }

    private function contextLabel(?string $context): string
    {
        return match ($this->normalizeContext($context)) {
            'venta' => 'Venta',
            'tasacion' => 'Tasacion',
            'exposicion' => 'Exposicion',
            'branding' => 'Branding',
            'otros' => 'Otros',
            default => 'Todos',
        };
    }

    private function contextForRows(array $rows): string
    {
        $types = collect($rows)
            ->map(fn (array $row) => $this->campaignType($row))
            ->filter()
            ->unique()
            ->values();

        return $types->count() === 1 ? (string) $types->first() : 'all';
    }

    private function resultLabelForContext(?string $context): string
    {
        return match ($this->normalizeContext($context)) {
            'venta' => 'Ventas',
            'tasacion' => 'Compras',
            'exposicion' => 'Oportunidades',
            'branding' => 'Leads',
            'otros' => 'Resultados',
            default => 'Resultados',
        };
    }

    private function resultCountForRow(array $row): int
    {
        return match ($this->campaignType($row)) {
            'venta' => (int) ($row['sales'] ?? 0),
            'tasacion' => (int) ($row['purchases'] ?? 0),
            'exposicion' => (int) ($row['opportunities'] ?? 0),
            'branding' => (int) ($row['leads_salesforce'] ?? 0),
            default => max((int) ($row['sales'] ?? 0), (int) ($row['purchases'] ?? 0), (int) ($row['opportunities'] ?? 0), (int) ($row['leads_salesforce'] ?? 0)),
        };
    }

    private function resultCountForRows(array $rows): int
    {
        return array_sum(array_map(fn (array $row) => $this->resultCountForRow($row), $rows));
    }

    private function costPerResultForRow(array $row): ?float
    {
        $resultCount = $this->resultCountForRow($row);

        if ($resultCount <= 0) {
            return null;
        }

        return $this->divide((float) ($row['spend'] ?? 0), $resultCount);
    }

    private function monthOptions(): array
    {
        $cursor = CarbonImmutable::now()->startOfMonth();
        $months = [];

        for ($index = 0; $index < 12; $index++) {
            $month = $cursor->subMonthsNoOverflow($index);
            $months[] = [
                'value' => $month->format('Y-m'),
                'label' => ucfirst($month->locale('es')->translatedFormat('F Y')),
                'start' => $month->startOfMonth()->toDateString(),
                'end' => $month->endOfMonth()->toDateString(),
            ];
        }

        return $months;
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
            ->merge($this->distinct('campaign_lead_attributions', $column))
            ->merge($this->distinct('campaign_platform_daily_metrics', $column))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function period(array $filters): array
    {
        $end = $this->parseDate($filters['end_date'], CarbonImmutable::now(self::REPORT_TIMEZONE))->endOfDay();
        $start = $this->parseDate($filters['start_date'], $end->subDays(30))->startOfDay();

        if ($start->greaterThan($end)) {
            $start = $end->subDays(30)->startOfDay();
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $end->addDay()->startOfDay()->utc(),
            'start_local' => $start,
            'end_local' => $end,
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, string>|string
     */
    private function campaignNameFilter(mixed $value): array|string
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $value
            ), fn (string $item): bool => $item !== ''));
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $value) ?: [])));

        return count($parts) > 1 ? $parts : $value;
    }

    private function rowKey(array $row): string
    {
        $sourceType = $row['campaign_source_type'] ?? '';
        $platform = $row['platform'] ?? 'unknown';
        $campaignId = $row['campaign_id'] ?? '';
        $campaignNameKey = $this->normalizer->key($row['campaign_name'] ?? '');

        /*
        * En campañas de plataforma, el campaign_id debe ser la clave principal.
        * No usamos account_id en la key porque puede venir informado en métricas
        * de plataforma y vacío en atribuciones Salesforce, lo que separaría
        * inversión y leads en filas distintas.
        */
        if ($sourceType === 'platform_campaign' && filled($campaignId)) {
            return implode('|', [
                $sourceType,
                $platform,
                $campaignId,
                $campaignNameKey,
            ]);
        }

        return implode('|', [
            $sourceType,
            $platform,
            $row['account_id'] ?? '',
            $campaignId,
            $campaignNameKey,
        ]);
    }

    private function hasAttributionSpecificFilters(array $filters): bool
    {
        foreach ([
            'source_acquired',
            'medium_acquired',
            'campaign_acquired',
            'campaign_source_type',
            'delegation',
            'zone',
            'lead_status',
            'has_opportunity',
            'has_reservation',
            'has_sale',
            'commercial_user',
            'vehicle_interest',
        ] as $key) {
            if ($key === 'campaign_source_type' && ($filters[$key] ?? null) === 'platform_campaign') {
                continue;
            }

            if (filled($filters[$key] ?? null)) {
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
            return CarbonImmutable::parse($value, self::REPORT_TIMEZONE)->setTimezone(self::REPORT_TIMEZONE);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function reportDateTime(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value)->setTimezone(self::REPORT_TIMEZONE);
    }

    private function lastUpdated(): ?CarbonImmutable
    {
        $updated = max(
            DB::table('campaign_lead_attributions')->max('updated_at') ?: '',
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
            'attributions_count' => DB::table('campaign_lead_attributions')->count(),
            'metrics_count' => CampaignPlatformDailyMetric::query()->count(),
            'attributions_updated_at' => DB::table('campaign_lead_attributions')->max('updated_at'),
            'metrics_updated_at' => CampaignPlatformDailyMetric::query()->max('updated_at'),
            'dashboard_cache_version' => Cache::get('campaign_dashboard_cache_version', 1),
        ];
    }
}
