<?php

namespace Tests\Feature;

use App\Models\SeoGa4OrganicDailyMetric;
use App\Models\SeoGa4OrganicKeyEventDailyMetric;
use App\Services\SeoAnalytics\Ga4OrganicConversionSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SeoGa4SyncTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $reportOptions = [];

    private bool $httpFaked = false;

    public function test_sync_uses_three_controlled_reports_preserves_decimals_and_fills_only_daily_zeros(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 America/New_York');
        $this->configureGa4();
        $this->fakeContextAndReports([
            'global' => [$this->row(['20260813'], ['3'])],
            'spain' => [
                $this->row(['20260813'], ['0.25']),
                $this->row(['20260814'], ['1.75']),
            ],
            'events' => [
                $this->row(['20260813', 'form_submit'], ['0.25']),
                $this->row(['20260814', '<script>alert(1)</script>'], ['1.75']),
            ],
        ]);

        $result = app(Ga4OrganicConversionSyncService::class)->sync(2);

        $this->assertSame('2026-08-14', $result['cutoff']->toDateString());
        $this->assertSame('America/New_York', $result['stats']['timezone']);
        $this->assertSame(3, $result['stats']['data_api_report_calls']);
        $this->assertSame('0.250000', SeoGa4OrganicDailyMetric::query()->where('country_scope', 'ESP')->whereDate('data_date', '2026-08-13')->value('key_events'));
        $this->assertSame('1.750000', SeoGa4OrganicDailyMetric::query()->where('country_scope', 'ESP')->whereDate('data_date', '2026-08-14')->value('key_events'));
        $this->assertSame('0.000000', SeoGa4OrganicDailyMetric::query()->where('country_scope', 'ALL')->whereDate('data_date', '2026-08-14')->value('key_events'));
        $this->assertSame(4, SeoGa4OrganicDailyMetric::query()->count());
        $this->assertSame(2, SeoGa4OrganicKeyEventDailyMetric::query()->count());
        $this->assertDatabaseMissing('seo_ga4_organic_key_event_daily_metrics', [
            'data_date' => '2026-08-13', 'event_name' => '<script>alert(1)</script>',
        ]);

        $reports = collect(Http::recorded())
            ->map(fn (array $exchange) => $exchange[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), ':runReport'))
            ->values();
        $this->assertCount(3, $reports);
        $this->assertTrue($reports->every(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer synthetic-token')));
        $this->assertPayload($reports[0], ['date'], false);
        $this->assertPayload($reports[1], ['date'], true);
        $this->assertPayload($reports[2], ['date', 'eventName'], true);

        $adminRequests = collect(Http::recorded())
            ->map(fn (array $exchange) => $exchange[0])
            ->filter(fn (Request $request): bool => str_starts_with($request->url(), 'https://analyticsadmin.googleapis.com/'))
            ->values();
        $this->assertCount(3, $adminRequests);
        $this->assertFalse($adminRequests->contains(
            fn (Request $request): bool => str_contains($request->url(), 'measurementProtocolSecrets')
        ));
    }

    public function test_resync_replaces_stale_event_detail_and_remote_failure_preserves_previous_rows(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 Europe/Madrid');
        $this->configureGa4();
        $this->fakeContextAndReports([
            'global' => [],
            'spain' => [],
            'events' => [
                $this->row(['20260813', 'event_a'], ['1']),
                $this->row(['20260814', 'event_b'], ['1']),
            ],
        ]);
        app(Ga4OrganicConversionSyncService::class)->sync(2);

        $this->fakeContextAndReports([
            'global' => [],
            'spain' => [],
            'events' => [$this->row(['20260813', 'event_a'], ['1.333333'])],
        ]);
        app(Ga4OrganicConversionSyncService::class)->sync(2);

        $this->assertSame(1, SeoGa4OrganicKeyEventDailyMetric::query()->count());
        $this->assertDatabaseMissing('seo_ga4_organic_key_event_daily_metrics', ['event_name' => 'event_b']);
        $this->assertSame('1.333333', SeoGa4OrganicKeyEventDailyMetric::query()->value('key_events'));

        app(Ga4OrganicConversionSyncService::class)->sync(2);
        $this->assertSame(4, SeoGa4OrganicDailyMetric::query()->count());
        $this->assertSame(1, SeoGa4OrganicKeyEventDailyMetric::query()->count());

        $this->fakeContextAndReports(['fail_reports' => true]);
        try {
            app(Ga4OrganicConversionSyncService::class)->sync(2);
            $this->fail('Expected Data API failure.');
        } catch (RuntimeException) {
            $this->assertSame(1, SeoGa4OrganicKeyEventDailyMetric::query()->count());
            $this->assertDatabaseHas('seo_ga4_organic_key_event_daily_metrics', ['event_name' => 'event_a']);
        }
    }

    public function test_context_rejects_missing_web_stream_key_events_timezone_and_invalid_lag(): void
    {
        $this->configureGa4();

        foreach ([0, 1, 8, 'invalid'] as $invalid) {
            config(['seo_analytics.ga4_reporting_lag_days' => $invalid]);
            $this->expectRuntimeException(fn () => app(Ga4OrganicConversionSyncService::class)->reportingLagDays());
        }

        config(['seo_analytics.ga4_reporting_lag_days' => 3]);
        $this->fakeContextAndReports(['streams' => [['name' => 'properties/123/dataStreams/1', 'type' => 'ANDROID_APP_DATA_STREAM']]]);
        $this->expectRuntimeException(fn () => app(Ga4OrganicConversionSyncService::class)->sync(2));

        $this->fakeContextAndReports(['key_events' => []]);
        $this->expectRuntimeException(fn () => app(Ga4OrganicConversionSyncService::class)->sync(2));

        $this->fakeContextAndReports(['timezone' => 'Invalid/Timezone']);
        $this->expectRuntimeException(fn () => app(Ga4OrganicConversionSyncService::class)->sync(2));

        $this->fakeContextAndReports(['timezone' => null]);
        $this->expectRuntimeException(fn () => app(Ga4OrganicConversionSyncService::class)->sync(2));
    }

    public function test_event_detail_paginates_with_offset_without_one_call_per_event(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 Europe/Madrid');
        $this->configureGa4();
        $this->fakeContextAndReports([
            'global' => [],
            'spain' => [],
            'events' => [
                $this->row(['20260813', 'event_a'], ['0.25']),
                $this->row(['20260814', 'event_b'], ['1.75']),
            ],
            'paginate_events' => true,
        ]);

        $result = app(Ga4OrganicConversionSyncService::class)->sync(2);

        $this->assertSame(4, $result['stats']['data_api_report_calls']);
        $this->assertSame(2, SeoGa4OrganicKeyEventDailyMetric::query()->count());
        $detailRequests = collect(Http::recorded())
            ->map(fn (array $exchange) => $exchange[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), ':runReport')
                && collect($request['dimensions'])->pluck('name')->all() === ['date', 'eventName'])
            ->values();
        $this->assertCount(2, $detailRequests);
        $this->assertSame(0, $detailRequests[0]['offset']);
        $this->assertSame(1, $detailRequests[1]['offset']);
    }

    public function test_thresholding_rejects_empty_and_present_rows_without_zero_filling(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 Europe/Madrid');
        $this->configureGa4();

        foreach ([[], [$this->row(['20260813'], ['1.25'])]] as $rows) {
            $this->fakeContextAndReports([
                'global' => $rows,
                'global_metadata' => ['subjectToThresholding' => true],
            ]);

            $exception = $this->captureRuntimeException(
                fn () => app(Ga4OrganicConversionSyncService::class)->sync(2)
            );

            $this->assertStringContainsString('umbrales de datos', $exception->getMessage());
            $this->assertSame(0, SeoGa4OrganicDailyMetric::query()->count());
            $this->assertSame(0, SeoGa4OrganicKeyEventDailyMetric::query()->count());
        }
    }

    public function test_data_loss_sampling_and_clean_or_absent_metadata_follow_the_quality_contract(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 Europe/Madrid');
        $this->configureGa4();

        $this->fakeContextAndReports([
            'global_metadata' => ['dataLossFromOtherRow' => true],
        ]);
        $dataLoss = $this->captureRuntimeException(
            fn () => app(Ga4OrganicConversionSyncService::class)->sync(2)
        );
        $this->assertStringContainsString('perdida de detalle', $dataLoss->getMessage());

        $this->fakeContextAndReports([
            'global_metadata' => ['samplingMetadatas' => [['samplesReadCount' => '10', 'samplingSpaceSize' => '100']]],
        ]);
        $sampled = $this->captureRuntimeException(
            fn () => app(Ga4OrganicConversionSyncService::class)->sync(2)
        );
        $this->assertStringContainsString('informe muestreado', $sampled->getMessage());

        $cleanMetadata = [
            'subjectToThresholding' => false,
            'dataLossFromOtherRow' => false,
            'samplingMetadatas' => [],
        ];
        $this->fakeContextAndReports([
            'global_metadata' => $cleanMetadata,
            'spain_metadata' => $cleanMetadata,
            'events_metadata' => $cleanMetadata,
        ]);
        app(Ga4OrganicConversionSyncService::class)->sync(2);
        $this->assertSame(4, SeoGa4OrganicDailyMetric::query()->count());

        SeoGa4OrganicDailyMetric::query()->delete();
        $this->fakeContextAndReports([]);
        app(Ga4OrganicConversionSyncService::class)->sync(2);
        $this->assertSame(4, SeoGa4OrganicDailyMetric::query()->count());
    }

    public function test_degraded_report_preserves_all_previous_ga4_rows(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 Europe/Madrid');
        $this->configureGa4();
        $this->fakeContextAndReports([
            'global' => [$this->row(['20260813'], ['2.5'])],
            'spain' => [$this->row(['20260813'], ['1.25'])],
            'events' => [$this->row(['20260813', 'existing_event'], ['1.25'])],
        ]);
        app(Ga4OrganicConversionSyncService::class)->sync(2);

        $dailyBefore = SeoGa4OrganicDailyMetric::query()->orderBy('id')->pluck('key_events', 'id')->all();
        $detailBefore = SeoGa4OrganicKeyEventDailyMetric::query()->orderBy('id')->pluck('key_events', 'event_name')->all();

        $this->fakeContextAndReports([
            'global' => [$this->row(['20260813'], ['99'])],
            'spain' => [$this->row(['20260813'], ['99'])],
            'events' => [$this->row(['20260813', 'replacement_event'], ['99'])],
            'events_metadata' => ['dataLossFromOtherRow' => true],
        ]);
        $this->captureRuntimeException(fn () => app(Ga4OrganicConversionSyncService::class)->sync(2));

        $this->assertSame($dailyBefore, SeoGa4OrganicDailyMetric::query()->orderBy('id')->pluck('key_events', 'id')->all());
        $this->assertSame($detailBefore, SeoGa4OrganicKeyEventDailyMetric::query()->orderBy('id')->pluck('key_events', 'event_name')->all());
        $this->assertDatabaseMissing('seo_ga4_organic_key_event_daily_metrics', ['event_name' => 'replacement_event']);
    }

    public function test_sampling_on_second_detail_page_rejects_sync_before_replacement(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 Europe/Madrid');
        $this->configureGa4();
        $this->fakeContextAndReports([
            'events' => [$this->row(['20260813', 'existing_event'], ['1'])],
        ]);
        app(Ga4OrganicConversionSyncService::class)->sync(2);

        $this->fakeContextAndReports([
            'events' => [
                $this->row(['20260813', 'first_page_event'], ['1']),
                $this->row(['20260814', 'sampled_page_event'], ['1']),
            ],
            'paginate_events' => true,
            'event_page_metadata' => [
                1 => ['samplingMetadatas' => [['samplesReadCount' => '10', 'samplingSpaceSize' => '100']]],
            ],
        ]);
        $exception = $this->captureRuntimeException(
            fn () => app(Ga4OrganicConversionSyncService::class)->sync(2)
        );

        $this->assertStringContainsString('informe muestreado', $exception->getMessage());
        $this->assertSame(['existing_event'], SeoGa4OrganicKeyEventDailyMetric::query()->pluck('event_name')->all());
        $this->assertDatabaseMissing('seo_ga4_organic_key_event_daily_metrics', ['event_name' => 'first_page_event']);
        $this->assertDatabaseMissing('seo_ga4_organic_key_event_daily_metrics', ['event_name' => 'sampled_page_event']);
    }

    /** @param array<string, mixed> $options */
    private function fakeContextAndReports(array $options): void
    {
        $this->reportOptions = $options;
        if ($this->httpFaked) {
            return;
        }
        $this->httpFaked = true;

        Http::fake(function (Request $request) {
            $options = $this->reportOptions;
            $streams = $options['streams'] ?? [[
                'name' => 'properties/123/dataStreams/1',
                'type' => 'WEB_DATA_STREAM',
                'displayName' => 'Web',
                'webStreamData' => ['defaultUri' => 'https://example.test'],
            ]];
            $keyEvents = $options['key_events'] ?? [['eventName' => 'form_submit']];
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'synthetic-token']);
            }
            if ($request->url() === 'https://analyticsadmin.googleapis.com/v1beta/properties/123') {
                return Http::response([
                    'name' => 'properties/123',
                    'timeZone' => array_key_exists('timezone', $options) ? $options['timezone'] : 'America/New_York',
                ]);
            }
            if (str_contains($request->url(), '/dataStreams')) {
                return Http::response(['dataStreams' => $streams]);
            }
            if (str_contains($request->url(), '/keyEvents')) {
                return Http::response(['keyEvents' => $keyEvents]);
            }
            if (str_ends_with($request->url(), ':runReport')) {
                if ($options['fail_reports'] ?? false) {
                    return Http::response(['error' => ['message' => 'synthetic failure']], 503);
                }

                $dimensions = collect($request['dimensions'])->pluck('name')->all();
                $country = collect(data_get($request->data(), 'dimensionFilter.andGroup.expressions', []))
                    ->contains(fn (array $expression): bool => data_get($expression, 'filter.fieldName') === 'countryId');
                $rows = $dimensions === ['date', 'eventName']
                    ? ($options['events'] ?? [])
                    : ($country ? ($options['spain'] ?? []) : ($options['global'] ?? []));

                if ($dimensions === ['date', 'eventName'] && ($options['paginate_events'] ?? false)) {
                    $response = [
                        'rows' => array_slice($rows, (int) $request['offset'], 1),
                        'rowCount' => count($rows),
                    ];
                    $pageMetadata = $options['event_page_metadata'][(int) $request['offset']] ?? null;
                    if (is_array($pageMetadata)) {
                        $response['metadata'] = $pageMetadata;
                    }

                    return Http::response($response);
                }

                $response = ['rows' => $rows, 'rowCount' => count($rows)];
                $metadataKey = $dimensions === ['date', 'eventName']
                    ? 'events_metadata'
                    : ($country ? 'spain_metadata' : 'global_metadata');
                if (array_key_exists($metadataKey, $options)) {
                    $response['metadata'] = $options[$metadataKey];
                }

                return Http::response($response);
            }

            return Http::response([], 404);
        });
    }

    /** @param array<int, string> $dimensions
     * @param  array<int, string>  $metrics
     * @return array<string, mixed>
     */
    private function row(array $dimensions, array $metrics): array
    {
        return [
            'dimensionValues' => array_map(fn (string $value): array => ['value' => $value], $dimensions),
            'metricValues' => array_map(fn (string $value): array => ['value' => $value], $metrics),
        ];
    }

    /** @param array<int, string> $dimensions */
    private function assertPayload(Request $request, array $dimensions, bool $spain): void
    {
        $this->assertSame($dimensions, collect($request['dimensions'])->pluck('name')->all());
        $this->assertSame(['keyEvents'], collect($request['metrics'])->pluck('name')->all());
        $filters = collect(data_get($request->data(), 'dimensionFilter.andGroup.expressions'));
        $this->assertTrue($filters->contains(fn (array $item): bool => data_get($item, 'filter.fieldName') === 'defaultChannelGroup'
            && data_get($item, 'filter.stringFilter.matchType') === 'EXACT'
            && data_get($item, 'filter.stringFilter.value') === 'Organic Search'));
        $this->assertTrue($filters->contains(fn (array $item): bool => data_get($item, 'filter.fieldName') === 'platform'
            && data_get($item, 'filter.stringFilter.value') === 'web'));
        $this->assertSame($spain, $filters->contains(fn (array $item): bool => data_get($item, 'filter.fieldName') === 'countryId'
            && data_get($item, 'filter.stringFilter.value') === 'ES'));
        $this->assertFalse($filters->contains(fn (array $item): bool => data_get($item, 'filter.fieldName') === 'sessionDefaultChannelGroup'));
    }

    private function configureGa4(): void
    {
        config([
            'services.google_analytics.client_id' => 'synthetic-client',
            'services.google_analytics.client_secret' => 'synthetic-secret',
            'services.google_analytics.refresh_token' => 'synthetic-refresh',
            'services.google_analytics.property_id' => '123',
            'seo_analytics.ga4_reporting_lag_days' => 3,
        ]);
    }

    private function expectRuntimeException(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    private function captureRuntimeException(callable $callback): RuntimeException
    {
        try {
            $callback();
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
