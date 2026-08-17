<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoSearchConsoleDailyMetric;
use App\Models\SeoSearchConsoleDimensionMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SearchConsoleSyncService
{
    public const DATASET = 'seo_search_console';

    public function __construct(
        private readonly SearchConsoleClient $client,
        private readonly BrandQueryClassifier $brands,
    ) {}

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public function configuredProperty(): ?string
    {
        return $this->client->configuredProperty();
    }

    /** @return array<string, mixed> */
    public function sync(int $days): array
    {
        $cutoff = $this->determineClosedThrough();
        $start = $cutoff->subDays($days - 1);
        $property = (string) $this->configuredProperty();
        $extractedAt = now();
        $regex = $this->brands->regex();

        $dailySets = [
            ['country_scope' => 'ALL', 'brand_segment' => 'all', 'filters' => []],
            ['country_scope' => 'ESP', 'brand_segment' => 'all', 'filters' => [$this->filter('country', 'equals', 'ESP')]],
            ['country_scope' => 'ESP', 'brand_segment' => 'brand', 'filters' => [
                $this->filter('country', 'equals', 'ESP'),
                $this->filter('query', 'includingRegex', $regex),
            ]],
            ['country_scope' => 'ESP', 'brand_segment' => 'non_brand', 'filters' => [
                $this->filter('country', 'equals', 'ESP'),
                $this->filter('query', 'excludingRegex', $regex),
            ]],
        ];

        $dailyRows = [];
        foreach ($dailySets as $set) {
            $response = $this->client->searchAnalytics($this->payload(
                $start,
                $cutoff,
                ['date'],
                $set['filters'],
                25000,
                'final',
            ));
            $byDate = collect($this->validatedRows($response, 'date'))
                ->keyBy(fn (array $row): string => (string) $row['keys'][0]);

            for ($date = $start; $date->lessThanOrEqualTo($cutoff); $date = $date->addDay()) {
                $metric = $this->metric($byDate->get($date->toDateString()));
                $dailyRows[] = [
                    'property' => $property,
                    'data_date' => $date->toDateString(),
                    'country_scope' => $set['country_scope'],
                    'brand_segment' => $set['brand_segment'],
                    ...$metric,
                    'source_timezone' => config('seo_analytics.search_console_timezone'),
                    'is_final' => true,
                    'extracted_at' => $extractedAt,
                    'created_at' => $extractedAt,
                    'updated_at' => $extractedAt,
                ];
            }
        }

        $dimensionRows = [];
        $dimensionCounts = ['query' => 0, 'page' => 0, 'country' => 0];
        foreach (config('seo_analytics.dashboard_ranges', [7, 28, 90]) as $periodDays) {
            $periodStart = $cutoff->subDays($periodDays - 1);
            foreach (['query', 'page', 'country'] as $dimension) {
                $countryScope = $dimension === 'country' ? 'ALL' : 'ESP';
                $filters = $countryScope === 'ESP' ? [$this->filter('country', 'equals', 'ESP')] : [];
                $response = $this->client->searchAnalytics($this->payload(
                    $periodStart,
                    $cutoff,
                    [$dimension],
                    $filters,
                    (int) config("seo_analytics.dimension_limits.{$dimension}"),
                    'final',
                ));

                foreach ($this->validatedRows($response, $dimension) as $index => $row) {
                    $value = (string) ($row['keys'][0] ?? '');
                    if ($value === '') {
                        continue;
                    }

                    $dimensionRows[] = [
                        'property' => $property,
                        'period_days' => $periodDays,
                        'period_start' => $periodStart->toDateString(),
                        'period_end' => $cutoff->toDateString(),
                        'dimension_type' => $dimension,
                        'country_scope' => $countryScope,
                        'rank' => $index + 1,
                        'dimension_value' => $value,
                        'dimension_hash' => hash('sha256', $value),
                        'brand_segment' => $dimension === 'query' ? $this->brands->classify($value) : null,
                        ...$this->metric($row),
                        'source_timezone' => config('seo_analytics.search_console_timezone'),
                        'extracted_at' => $extractedAt,
                        'created_at' => $extractedAt,
                        'updated_at' => $extractedAt,
                    ];
                    $dimensionCounts[$dimension]++;
                }
            }
        }

        DB::transaction(function () use ($dailyRows, $dimensionRows, $property): void {
            foreach (array_chunk($dailyRows, 500) as $chunk) {
                SeoSearchConsoleDailyMetric::query()->upsert(
                    $chunk,
                    ['property', 'data_date', 'country_scope', 'brand_segment'],
                    ['clicks', 'impressions', 'ctr', 'position', 'source_timezone', 'is_final', 'extracted_at', 'updated_at'],
                );
            }

            SeoSearchConsoleDimensionMetric::query()->where('property', $property)->delete();
            foreach (array_chunk($dimensionRows, 500) as $chunk) {
                SeoSearchConsoleDimensionMetric::query()->insert($chunk);
            }
        });

        return [
            'period_start' => $start,
            'period_end' => $cutoff,
            'cutoff' => $cutoff,
            'stats' => [
                'daily_rows' => count($dailyRows),
                'query_rows' => $dimensionCounts['query'],
                'page_rows' => $dimensionCounts['page'],
                'country_rows' => $dimensionCounts['country'],
                'property' => $property,
                'range_days' => $days,
            ],
        ];
    }

    public function determineClosedThrough(): CarbonImmutable
    {
        $timezone = (string) config('seo_analytics.search_console_timezone');
        $end = CarbonImmutable::now($timezone)->startOfDay();
        $start = $end->subDays(14);
        $response = $this->client->searchAnalytics($this->payload($start, $end, ['date'], [], 25000, 'all'));
        $incomplete = data_get($response, 'metadata.first_incomplete_date')
            ?? data_get($response, 'metadata.firstIncompleteDate');

        if (filled($incomplete)) {
            $cutoff = CarbonImmutable::parse($incomplete, $timezone)->subDay()->startOfDay();

            if ($cutoff->greaterThanOrEqualTo($end)) {
                throw new RuntimeException('Search Console no permite confirmar que el cutoff este finalizado.');
            }

            return $cutoff;
        }

        $dates = collect($this->validatedRows($response, 'date'))
            ->map(fn (array $row): ?string => $row['keys'][0] ?? null)
            ->filter()
            ->sort()
            ->values();

        if ($dates->isEmpty()) {
            throw new RuntimeException('Search Console no permite determinar un dia finalizado fiable.');
        }

        $cutoff = CarbonImmutable::parse($dates->last(), $timezone)->startOfDay();
        if ($cutoff->greaterThanOrEqualTo($end)) {
            throw new RuntimeException('Search Console no permite confirmar que la ultima fecha este finalizada.');
        }

        return $cutoff;
    }

    /** @param array<int, string> $dimensions
     * @param  array<int, array<string, string>>  $filters
     * @return array<string, mixed>
     */
    private function payload(
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $dimensions,
        array $filters,
        int $rowLimit,
        string $dataState,
    ): array {
        $payload = [
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'dimensions' => $dimensions,
            'dataState' => $dataState,
            'rowLimit' => $rowLimit,
        ];

        if ($filters !== []) {
            $payload['dimensionFilterGroups'] = [['groupType' => 'and', 'filters' => $filters]];
        }

        return $payload;
    }

    /** @return array{dimension: string, operator: string, expression: string} */
    private function filter(string $dimension, string $operator, string $expression): array
    {
        return compact('dimension', 'operator', 'expression');
    }

    /** @param array<string, mixed>|null $row
     * @return array{clicks: int, impressions: int, ctr: ?float, position: ?float}
     */
    private function metric(?array $row): array
    {
        $clicks = (int) round((float) ($row['clicks'] ?? 0));
        $impressions = (int) round((float) ($row['impressions'] ?? 0));

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 && isset($row['ctr']) ? (float) $row['ctr'] : null,
            'position' => $impressions > 0 && isset($row['position']) ? (float) $row['position'] : null,
        ];
    }

    /** @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function validatedRows(array $response, string $dimension): array
    {
        $rows = $response['rows'] ?? [];
        if (! is_array($rows)) {
            throw new RuntimeException("Search Console devolvio rows invalidas para {$dimension}.");
        }

        foreach ($rows as $row) {
            if (! is_array($row)
                || ! is_array($row['keys'] ?? null)
                || blank($row['keys'][0] ?? null)
                || (isset($row['clicks']) && ! is_numeric($row['clicks']))
                || (isset($row['impressions']) && ! is_numeric($row['impressions']))
                || (isset($row['ctr']) && ! is_numeric($row['ctr']))
                || (isset($row['position']) && ! is_numeric($row['position']))) {
                throw new RuntimeException("Search Console devolvio una fila invalida para {$dimension}.");
            }
        }

        return array_values($rows);
    }
}
