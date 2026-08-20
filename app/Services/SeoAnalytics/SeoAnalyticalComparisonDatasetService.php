<?php

namespace App\Services\SeoAnalytics;

use App\Models\AnalyticalMetricSnapshot;
use Illuminate\Database\Eloquent\Builder;

final class SeoAnalyticalComparisonDatasetService
{
    public function __construct(
        private readonly SeoAnalyticalMetricRegistry $registry,
        private readonly SearchConsoleClient $searchConsole,
        private readonly GoogleAnalyticsClient $analytics,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function build(): array
    {
        $identities = $this->currentIdentities();
        if ($identities === []) {
            return [];
        }

        $latest = AnalyticalMetricSnapshot::query()
            ->selectRaw('metric_key, scope_key, source_identifier_hash, MAX(data_date) as max_data_date')
            ->where('module_key', SeoAnalyticalMetricRegistry::MODULE)
            ->where(fn (Builder $query) => $this->applyIdentityFilter($query, $identities))
            ->groupBy('metric_key', 'scope_key', 'source_identifier_hash');

        $snapshots = AnalyticalMetricSnapshot::query()
            ->from('analytical_metric_snapshots as snapshots')
            ->select('snapshots.*')
            ->joinSub($latest, 'latest_snapshots', function ($join): void {
                $join->on('snapshots.metric_key', '=', 'latest_snapshots.metric_key')
                    ->on('snapshots.scope_key', '=', 'latest_snapshots.scope_key')
                    ->on('snapshots.source_identifier_hash', '=', 'latest_snapshots.source_identifier_hash')
                    ->on('snapshots.data_date', '=', 'latest_snapshots.max_data_date');
            })
            ->where('snapshots.module_key', SeoAnalyticalMetricRegistry::MODULE)
            ->where(fn (Builder $query) => $this->applyIdentityFilter($query, $identities, 'snapshots.'))
            ->get()
            ->keyBy('metric_key');

        return collect($this->registry->metrics())
            ->map(function (array $definition) use ($snapshots): ?array {
                $snapshot = $snapshots->get($definition['key']);

                return $snapshot ? $this->present($definition, $snapshot) : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private function currentIdentities(): array
    {
        $identities = [
            'salesforce' => hash('sha256', SeoAnalyticalMetricRegistry::SALESFORCE_SOURCE_IDENTIFIER),
        ];
        $searchProperty = $this->searchConsole->configuredProperty();
        if (is_string($searchProperty) && $searchProperty !== '') {
            $identities['search_console'] = hash('sha256', $searchProperty);
        }
        $ga4Property = $this->analytics->configuredPropertyId();
        if (is_string($ga4Property) && $ga4Property !== '') {
            $identities['ga4'] = hash('sha256', $ga4Property);
        }

        return $identities;
    }

    /** @param array<string, string> $identities */
    private function applyIdentityFilter(Builder $query, array $identities, string $prefix = ''): void
    {
        foreach ($identities as $source => $hash) {
            $method = $source === array_key_first($identities) ? 'where' : 'orWhere';
            $query->{$method}(function (Builder $identityQuery) use ($source, $hash, $prefix): void {
                $identityQuery
                    ->where($prefix.'source_key', $source)
                    ->where($prefix.'source_identifier_hash', $hash);
            });
        }
    }

    /**
     * @param  array{key: string, label: string, source: string, source_label: string, scope: string, format: string, field: string}  $definition
     * @return array<string, mixed>
     */
    private function present(array $definition, AnalyticalMetricSnapshot $snapshot): array
    {
        return [
            'metric_key' => $snapshot->metric_key,
            'label' => $snapshot->metric_label,
            'source' => $definition['source_label'],
            'scope' => $snapshot->scope_key,
            'data_date' => $snapshot->data_date->toDateString(),
            'current' => $this->formatSourceValue($snapshot->current_value, $snapshot->value_format),
            'baseline' => $this->formatDerivedValue($snapshot->baseline_value, $snapshot->value_format),
            'absolute_change' => $this->formatAbsolute($snapshot->absolute_change, $snapshot->value_format),
            'relative_change' => $this->formatPercent($snapshot->relative_change, true),
            'd364' => $this->formatSourceValue($snapshot->d364_value, $snapshot->value_format),
            'reference_count' => $snapshot->reference_count,
            'coverage' => $snapshot->reference_count.'/4'.($snapshot->reference_count < 3 ? ' · Sin histórico suficiente' : ''),
            'is_evaluable' => $snapshot->is_evaluable,
            'evaluation_reason' => $snapshot->evaluation_reason,
            'baseline_is_zero' => $snapshot->baseline_value !== null && (float) $snapshot->baseline_value === 0.0,
        ];
    }

    private function formatSourceValue(?string $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'integer' => number_format((float) $value, 0, ',', '.'),
            'percent' => number_format((float) $value * 100, 2, ',', '.').'%',
            default => number_format((float) $value, 2, ',', '.'),
        };
    }

    private function formatDerivedValue(?string $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'percent' => number_format((float) $value * 100, 2, ',', '.').'%',
            default => number_format((float) $value, 2, ',', '.'),
        };
    }

    private function formatAbsolute(?string $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        $numeric = (float) $value;
        $sign = $numeric > 0 ? '+' : '';

        return match ($format) {
            'integer' => $sign.number_format($numeric, 2, ',', '.'),
            'percent' => $sign.number_format($numeric * 100, 2, ',', '.').' pp',
            default => $sign.number_format($numeric, 2, ',', '.'),
        };
    }

    private function formatPercent(?string $value, bool $signed = false): string
    {
        if ($value === null) {
            return '—';
        }

        $numeric = (float) $value * 100;
        $sign = $signed && $numeric > 0 ? '+' : '';

        return $sign.number_format($numeric, 2, ',', '.').'%';
    }
}
