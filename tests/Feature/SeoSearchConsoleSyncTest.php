<?php

namespace Tests\Feature;

use App\Models\SeoSearchConsoleDailyMetric;
use App\Models\SeoSearchConsoleDimensionMetric;
use App\Services\SeoAnalytics\SearchConsoleSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoSearchConsoleSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_daily_aggregates_fill_missing_closed_days_and_rankings_are_bounded_and_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 America/Los_Angeles');
        $this->configureSearchConsole();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'synthetic-token']);
            }

            $dimensions = $request['dimensions'] ?? [];
            if (($request['dataState'] ?? null) === 'all') {
                return Http::response([
                    'rows' => [
                        ['keys' => ['2026-08-14']],
                        ['keys' => ['2026-08-15']],
                        ['keys' => ['2026-08-16']],
                    ],
                    'metadata' => ['first_incomplete_date' => '2026-08-17'],
                ]);
            }

            if ($dimensions === ['date']) {
                return Http::response(['rows' => [[
                    'keys' => ['2026-08-16'], 'clicks' => 4, 'impressions' => 20, 'ctr' => .2, 'position' => 3.5,
                ]]]);
            }

            $dimension = $dimensions[0];
            $value = ['query' => 'hr motor sevilla', 'page' => 'https://example.test/stock', 'country' => 'esp'][$dimension];

            return Http::response(['rows' => [[
                'keys' => [$value], 'clicks' => 2, 'impressions' => 10, 'ctr' => .2, 'position' => 4,
            ]]]);
        });

        $this->artisan('seo:sync-search-console', ['--days' => 2])->assertSuccessful();

        $this->assertDatabaseHas('report_sync_runs', [
            'dataset' => 'seo_search_console', 'source' => 'google_search_console', 'status' => 'completed',
        ]);
        $this->assertSame(8, SeoSearchConsoleDailyMetric::query()->count());
        $this->assertSame(9, SeoSearchConsoleDimensionMetric::query()->count());
        $this->assertDatabaseHas('seo_search_console_daily_metrics', [
            'data_date' => '2026-08-15', 'country_scope' => 'ALL', 'brand_segment' => 'all',
            'clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null, 'is_final' => true,
        ]);
        $this->assertDatabaseHas('seo_search_console_dimension_metrics', [
            'period_days' => 28, 'dimension_type' => 'query', 'brand_segment' => 'brand', 'rank' => 1,
        ]);

        $analyticsRequests = collect(Http::recorded())
            ->map(fn (array $record) => $record[0])
            ->filter(fn (Request $request): bool => str_contains($request->url(), '/searchAnalytics/query'));
        $this->assertCount(14, $analyticsRequests);
        $this->assertTrue($analyticsRequests
            ->reject(fn (Request $request): bool => $request['dataState'] === 'all')
            ->every(fn (Request $request): bool => $request['dataState'] === 'final'));

        $dailyRequests = $analyticsRequests->filter(
            fn (Request $request): bool => $request['dimensions'] === ['date'] && $request['dataState'] === 'final'
        );
        $this->assertCount(4, $dailyRequests);
        $filters = $dailyRequests->map(fn (Request $request): array => data_get($request->data(), 'dimensionFilterGroups.0.filters', []));
        $this->assertTrue($filters->contains(fn (array $set): bool => $set === []));
        $this->assertTrue($filters->contains(fn (array $set): bool => $this->hasFilter($set, 'country', 'equals', 'ESP') && count($set) === 1));
        $this->assertTrue($filters->contains(fn (array $set): bool => $this->hasFilter($set, 'country', 'equals', 'ESP') && $this->hasFilter($set, 'query', 'includingRegex')));
        $this->assertTrue($filters->contains(fn (array $set): bool => $this->hasFilter($set, 'country', 'equals', 'ESP') && $this->hasFilter($set, 'query', 'excludingRegex')));

        app(SearchConsoleSyncService::class)->sync(2);
        $this->assertSame(8, SeoSearchConsoleDailyMetric::query()->count());
        $this->assertSame(9, SeoSearchConsoleDimensionMetric::query()->count());
    }

    public function test_no_reliable_cutoff_fails_without_replacing_existing_rankings(): void
    {
        $this->configureSearchConsole();
        SeoSearchConsoleDimensionMetric::query()->create([
            'property' => 'sc-domain:example.test', 'period_days' => 7,
            'period_start' => '2026-08-01', 'period_end' => '2026-08-07',
            'dimension_type' => 'query', 'country_scope' => 'ESP', 'rank' => 1,
            'dimension_value' => 'ranking anterior', 'dimension_hash' => hash('sha256', 'ranking anterior'),
            'brand_segment' => 'non_brand', 'clicks' => 1, 'impressions' => 1,
            'ctr' => 1, 'position' => 1, 'source_timezone' => 'America/Los_Angeles', 'extracted_at' => now(),
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-token']),
            '*' => Http::response([]),
        ]);

        try {
            $this->artisan('seo:sync-search-console', ['--days' => 7])->assertFailed();
            $this->assertDatabaseHas('report_sync_runs', ['dataset' => 'seo_search_console', 'status' => 'failed']);
            $this->assertDatabaseHas('seo_search_console_dimension_metrics', ['dimension_value' => 'ranking anterior']);
        } catch (\RuntimeException $exception) {
            $this->fail('The command must convert the exception into FAILURE: '.$exception->getMessage());
        }
    }

    public function test_cutoff_without_metadata_uses_latest_returned_final_date_even_if_dates_are_discontinuous(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 America/Los_Angeles');
        $this->configureSearchConsole();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-token']),
            '*' => Http::response(['rows' => [
                ['keys' => ['2026-08-13']],
                ['keys' => ['2026-08-16']],
            ]]),
        ]);

        $this->assertSame('2026-08-16', app(SearchConsoleSyncService::class)->determineClosedThrough()->toDateString());
        CarbonImmutable::setTestNow();
    }

    private function configureSearchConsole(): void
    {
        config([
            'services.google_search_console.client_id' => 'synthetic-client',
            'services.google_search_console.client_secret' => 'synthetic-secret',
            'services.google_search_console.refresh_token' => 'synthetic-refresh',
            'services.google_search_console.property' => 'sc-domain:example.test',
        ]);
    }

    /** @param array<int, array<string, string>> $filters */
    private function hasFilter(array $filters, string $dimension, string $operator, ?string $expression = null): bool
    {
        return collect($filters)->contains(function (array $filter) use ($dimension, $operator, $expression): bool {
            return ($filter['dimension'] ?? null) === $dimension
                && ($filter['operator'] ?? null) === $operator
                && ($expression === null || ($filter['expression'] ?? null) === $expression);
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
