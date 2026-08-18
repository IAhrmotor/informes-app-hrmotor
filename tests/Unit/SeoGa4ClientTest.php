<?php

namespace Tests\Unit;

use App\Services\SeoAnalytics\GoogleAnalyticsClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SeoGa4ClientTest extends TestCase
{
    public function test_data_streams_are_paginated_and_only_safe_metadata_is_returned(): void
    {
        $this->configureGa4();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-token']),
            'https://analyticsadmin.googleapis.com/v1beta/properties/123/dataStreams*' => Http::sequence()
                ->push(['dataStreams' => [[
                    'name' => 'properties/123/dataStreams/1', 'type' => 'WEB_DATA_STREAM',
                    'displayName' => 'Web principal', 'webStreamData' => ['defaultUri' => 'https://example.test'],
                    'measurementProtocolSecrets' => [['secretValue' => 'must-not-leak']],
                ]], 'nextPageToken' => 'page-2'])
                ->push(['dataStreams' => [[
                    'name' => 'properties/123/dataStreams/2', 'type' => 'ANDROID_APP_DATA_STREAM',
                    'displayName' => 'Android',
                ]]]),
        ]);

        $streams = app(GoogleAnalyticsClient::class)->dataStreams();

        $this->assertCount(2, $streams);
        $this->assertSame('https://example.test', $streams[0]['default_uri']);
        $this->assertNull($streams[1]['default_uri']);
        $this->assertArrayNotHasKey('measurementProtocolSecrets', $streams[0]);
        $this->assertStringNotContainsString('must-not-leak', json_encode($streams, JSON_THROW_ON_ERROR));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://analyticsadmin.googleapis.com/v1beta/properties/123/dataStreams?pageSize=200&pageToken=page-2');
    }

    public function test_run_report_posts_to_configured_property_with_bearer_and_sanitizes_errors(): void
    {
        $this->configureGa4();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123:runReport' => Http::response([
                'rows' => [], 'rowCount' => 0,
            ]),
        ]);

        $payload = ['dimensions' => [['name' => 'date']], 'metrics' => [['name' => 'keyEvents']], 'limit' => 10000, 'offset' => 0];
        $client = app(GoogleAnalyticsClient::class);
        $this->assertSame(0, $client->runReport($payload)['rowCount']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://analyticsdata.googleapis.com/v1beta/properties/123:runReport'
            && $request->hasHeader('Authorization', 'Bearer synthetic-token')
            && $request['metrics'] === [['name' => 'keyEvents']]);

        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/123:runReport' => Http::response([
                'error' => ['message' => 'refresh_token=synthetic-refresh'],
            ], 403),
        ]);
        try {
            $client->runReport($payload);
            $this->fail('Expected sanitized Data API failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('synthetic-refresh', $exception->getMessage());
        }
    }

    private function configureGa4(): void
    {
        config([
            'services.google_analytics.client_id' => 'synthetic-client',
            'services.google_analytics.client_secret' => 'synthetic-secret',
            'services.google_analytics.refresh_token' => 'synthetic-refresh',
            'services.google_analytics.property_id' => '123',
        ]);
    }
}
