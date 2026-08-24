<?php

namespace App\Services\SeoAnalytics;

use Carbon\CarbonImmutable;

final class SeoExecutiveDailyReportDatasetService
{
    public function __construct(
        private readonly SeoAnalyticalMetricRegistry $registry,
        private readonly SeoAnalyticalComparisonDatasetService $comparisons,
        private readonly SeoSourceStatusDatasetService $sourceStatus,
        private readonly SeoTechnicalHealthDatasetService $technicalHealth,
    ) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $reportDate): array
    {
        $comparisons = collect($this->comparisons->build())->keyBy('metric_key');
        $metrics = collect($this->registry->metrics())
            ->map(function (array $definition) use ($comparisons): array {
                $metric = $comparisons->get($definition['key']);

                return is_array($metric) ? $metric : $this->missingMetric($definition);
            })
            ->values()
            ->all();
        $counts = $this->counts($metrics);
        $sourceStatus = $this->sourceStatus->build();
        $health = $this->health($this->technicalHealth->summary());

        return [
            'report_date' => $reportDate->toDateString(),
            'subject' => 'SEO y Analytics · Resumen diario · '.$reportDate->format('d/m/Y'),
            'executive_summary' => $this->executiveSummary($counts),
            'counts' => $counts,
            'metrics' => $metrics,
            'highlighted_metrics' => collect($metrics)
                ->whereIn('status', ['observation', 'deviation', 'critical', 'not-evaluable'])
                ->values()
                ->all(),
            'sources' => $sourceStatus['sources'],
            'source_data_dates' => collect($metrics)
                ->mapWithKeys(fn (array $metric): array => [$metric['metric_key'] => $metric['data_date']])
                ->all(),
            'health' => $health,
            'rule_versions' => collect($metrics)
                ->pluck('rule_version')
                ->filter(fn (mixed $version): bool => is_string($version) && $version !== '')
                ->unique()
                ->values()
                ->all(),
            'dashboard_url' => route('reports.seo-analytics.index', ['section' => 'summary']),
        ];
    }

    /** @param array{key: string, label: string, source_label: string, scope: string} $definition
     * @return array<string, mixed>
     */
    private function missingMetric(array $definition): array
    {
        return [
            'metric_key' => $definition['key'],
            'label' => $definition['label'],
            'source' => $definition['source_label'],
            'scope' => $definition['scope'],
            'data_date' => null,
            'current' => '—',
            'baseline' => '—',
            'absolute_change' => '—',
            'relative_change' => '—',
            'd364' => '—',
            'reference_count' => 0,
            'coverage' => '0/4 · Sin histórico suficiente',
            'is_evaluable' => false,
            'evaluation_reason' => 'missing_snapshot',
            'baseline_is_zero' => false,
            'status' => 'not-evaluable',
            'direction' => 'not_evaluable',
            'direction_label' => 'No evaluable',
            'magnitude_band' => 'not-evaluable',
            'reason_code' => 'missing_snapshot',
            'reading' => 'Sin datos disponibles para evaluar.',
            'rule_version' => null,
        ];
    }

    /** @param array<int, array<string, mixed>> $metrics
     * @return array<string, int>
     */
    private function counts(array $metrics): array
    {
        $rows = collect($metrics);

        return [
            'ok' => $rows->where('status', 'ok')->count(),
            'observation' => $rows->where('status', 'observation')->count(),
            'observation_favorable' => $rows->where('status', 'observation')->where('direction', 'favorable')->count(),
            'observation_unfavorable' => $rows->where('status', 'observation')->where('direction', 'unfavorable')->count(),
            'deviation' => $rows->where('status', 'deviation')->count(),
            'critical' => $rows->where('status', 'critical')->count(),
            'not_evaluable' => $rows->where('status', 'not-evaluable')->count(),
        ];
    }

    /** @param array<string, int> $counts */
    private function executiveSummary(array $counts): string
    {
        $parts = [];
        if ($counts['critical'] > 0) {
            $parts[] = $counts['critical'].' '.($counts['critical'] === 1 ? 'métrica en estado Crítico' : 'métricas en estado Crítico');
        }
        if ($counts['deviation'] > 0) {
            $parts[] = $counts['deviation'].' '.($counts['deviation'] === 1 ? 'desviación relevante' : 'desviaciones relevantes');
        }
        if ($counts['observation'] > 0) {
            $parts[] = $counts['observation'].' '.($counts['observation'] === 1 ? 'observación' : 'observaciones');
        }
        if ($parts === []) {
            $parts[] = 'Sin desviaciones relevantes en las métricas evaluables';
        }
        if ($counts['observation_favorable'] > 0) {
            $parts[] = $counts['observation_favorable'].' '.($counts['observation_favorable'] === 1 ? 'oportunidad / posible anomalía favorable' : 'oportunidades / posibles anomalías favorables');
        }
        if ($counts['not_evaluable'] > 0) {
            $parts[] = $counts['not_evaluable'].' '.($counts['not_evaluable'] === 1 ? 'métrica no evaluable' : 'métricas no evaluables');
        }

        return implode('. ', $parts).'.';
    }

    /** @param array<string, mixed> $health
     * @return array<string, mixed>
     */
    private function health(array $health): array
    {
        $stats = is_array($health['stats'] ?? null) ? $health['stats'] : [];
        $sitemapComplete = data_get($stats, 'sitemap_scan_complete') === true
            || data_get($stats, 'sitemap_scan_complete') === 1;

        return [
            'available' => (bool) ($health['available'] ?? false),
            'source' => $health['source'] ?? [
                'key' => 'technical-health',
                'title' => 'Salud técnica SEO',
                'badge' => 'Sin comprobaciones',
                'detail' => 'Todavía no existen comprobaciones.',
            ],
            'check_date' => data_get($stats, 'check_date'),
            'checked_urls' => (int) data_get($stats, 'checked_urls', 0),
            'http_4xx' => (int) data_get($stats, 'http_4xx', 0),
            'http_5xx' => (int) data_get($stats, 'http_5xx', 0),
            'network_errors' => (int) data_get($stats, 'network_errors', 0),
            'noindex_urls' => (int) data_get($stats, 'noindex_urls', 0),
            'canonical_mismatch_urls' => (int) data_get($stats, 'canonical_mismatch_urls', 0),
            'redirected_urls' => (int) data_get($stats, 'redirected_urls', 0),
            'sitemap_scan_complete' => $sitemapComplete,
            'sitemap_label' => $sitemapComplete ? 'Comprobación de sitemap completa' : 'Comprobación de sitemap parcial',
            'outside_sitemap_urls' => $sitemapComplete
                ? (int) data_get($stats, 'outside_sitemap_urls', 0)
                : null,
        ];
    }
}
