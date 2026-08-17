<?php

namespace Tests\Unit;

use App\Services\SeoAnalytics\GoogleAnalyticsClient;
use App\Services\SeoAnalytics\SearchConsoleClient;
use App\Services\SeoAnalytics\SistrixClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SeoIntegrationClientsTest extends TestCase
{
    public function test_clients_fail_closed_as_unconfigured_without_http(): void
    {
        $this->setGoogleConfiguration();
        config(['services.sistrix.api_key' => null]);
        Http::fake();

        $this->assertFalse(app(SearchConsoleClient::class)->configured());
        $this->assertFalse(app(GoogleAnalyticsClient::class)->configured());
        $this->assertFalse(app(SistrixClient::class)->configured());
        $this->assertFalse(app(SearchConsoleClient::class)->diagnose()['configured']);
        $this->assertFalse(app(GoogleAnalyticsClient::class)->diagnose()['configured']);
        $this->assertFalse(app(SistrixClient::class)->diagnose()['configured']);
        Http::assertNothingSent();
    }

    public function test_search_console_refreshes_oauth_and_checks_the_exact_target_property(): void
    {
        $this->setGoogleConfiguration(searchProperty: 'sc-domain:hrmotor.com');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-token']),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
                ['siteUrl' => 'sc-domain:hrmotor.com', 'permissionLevel' => 'siteOwner'],
                ['siteUrl' => 'https://example.test/', 'permissionLevel' => 'siteRestrictedUser'],
            ]]),
        ]);

        $result = app(SearchConsoleClient::class)->diagnose();

        $this->assertTrue($result['configured']);
        $this->assertTrue($result['accessible']);
        $this->assertCount(2, $result['sites']);

        config(['services.google_search_console.property' => 'sc-domain:missing.test']);
        $this->assertFalse(app(SearchConsoleClient::class)->diagnose()['accessible']);
    }

    public function test_search_console_errors_are_sanitized(): void
    {
        $secret = 'secret-refresh-value';
        $this->setGoogleConfiguration(searchProperty: 'sc-domain:hrmotor.com', refreshToken: $secret);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => ['type' => 'invalid_grant', 'message' => 'refresh_token='.$secret],
            ], 400),
        ]);

        try {
            app(SearchConsoleClient::class)->diagnose();
            $this->fail('Expected sanitized OAuth failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid_grant', $exception->getMessage());
            $this->assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    public function test_ga4_checks_property_metadata_timezone_and_paginates_key_events(): void
    {
        $this->setGoogleConfiguration(analyticsProperty: '313695489');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-token']),
            'https://analyticsadmin.googleapis.com/v1beta/properties/313695489' => Http::response([
                'name' => 'properties/313695489',
                'timeZone' => 'Europe/Madrid',
            ]),
            'https://analyticsdata.googleapis.com/v1beta/properties/313695489/metadata' => Http::response([
                'name' => 'properties/313695489/metadata',
                'dimensions' => [['apiName' => 'country']],
                'metrics' => [['apiName' => 'activeUsers'], ['apiName' => 'eventCount']],
            ]),
            'https://analyticsadmin.googleapis.com/v1beta/properties/313695489/keyEvents*' => Http::sequence()
                ->push(['keyEvents' => [['eventName' => 'form_submit']], 'nextPageToken' => 'page-2'])
                ->push(['keyEvents' => [['eventName' => 'phone_click']]]),
        ]);

        $result = app(GoogleAnalyticsClient::class)->diagnose();

        $this->assertTrue($result['accessible']);
        $this->assertTrue($result['metadata']);
        $this->assertSame(1, $result['dimensions']);
        $this->assertSame(2, $result['metrics']);
        $this->assertSame('Europe/Madrid', $result['timezone']);
        $this->assertSame(['form_submit', 'phone_click'], $result['key_events']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://analyticsadmin.googleapis.com/v1beta/properties/313695489/keyEvents?pageSize=200&pageToken=page-2');
    }

    public function test_sistrix_uses_only_the_credits_endpoint_and_never_exposes_the_key(): void
    {
        $secret = 'synthetic-sistrix-key';
        config(['services.sistrix.api_key' => $secret]);
        Http::fake([
            'https://api.sistrix.com/credits' => Http::response(['answer' => [['credits' => 1000]]]),
        ]);

        $result = app(SistrixClient::class)->diagnose();

        $this->assertTrue($result['api_accessible']);
        $this->assertSame('pending', $result['ai_check']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.sistrix.com/credits'
            && $request->method() === 'POST'
            && $request['api_key'] === $secret
            && ! str_contains($request->url(), $secret)
            && ! str_contains($request->url(), 'ai.check'));

        Http::fake([
            'https://api.sistrix.com/credits' => Http::response(['error' => ['message' => 'api_key='.$secret]], 401),
        ]);

        try {
            app(SistrixClient::class)->diagnose();
            $this->fail('Expected sanitized SISTRIX failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    private function setGoogleConfiguration(
        ?string $searchProperty = null,
        ?string $analyticsProperty = null,
        string $refreshToken = 'synthetic-refresh-token',
    ): void {
        config([
            'services.google_search_console.client_id' => $searchProperty ? 'search-client' : null,
            'services.google_search_console.client_secret' => $searchProperty ? 'search-secret' : null,
            'services.google_search_console.refresh_token' => $searchProperty ? $refreshToken : null,
            'services.google_search_console.property' => $searchProperty,
            'services.google_analytics.client_id' => $analyticsProperty ? 'analytics-client' : null,
            'services.google_analytics.client_secret' => $analyticsProperty ? 'analytics-secret' : null,
            'services.google_analytics.refresh_token' => $analyticsProperty ? $refreshToken : null,
            'services.google_analytics.property_id' => $analyticsProperty,
        ]);
    }
}
