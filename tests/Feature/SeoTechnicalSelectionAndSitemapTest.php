<?php

namespace Tests\Feature;

use App\Models\SeoSearchConsoleDimensionMetric;
use App\Models\SeoTechnicalUrl;
use App\Services\SeoAnalytics\SeoTechnicalDnsResolver;
use App\Services\SeoAnalytics\SeoTechnicalHealthSyncService;
use App\Services\SeoAnalytics\SeoTechnicalSitemapService;
use App\Services\SeoAnalytics\SeoTechnicalUrlNormalizer;
use App\Services\SeoAnalytics\SeoTechnicalUrlSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Tests\TestCase;

class SeoTechnicalSelectionAndSitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_selector_prioritizes_home_strategic_and_local_search_console_ranking(): void
    {
        $this->configureSite([
            'strategic_urls' => ['https://example.test/sale#form', 'https://example.test/sale'],
            'max_urls' => 3,
        ]);
        config(['services.google_search_console.property' => 'sc-domain:example.test']);
        $this->searchConsolePage(1, 'https://evil.test/private', 10);
        $this->searchConsolePage(2, 'https://example.test/vehicle/traffic', 20);

        $result = app(SeoTechnicalUrlSelector::class)->select();

        $this->assertSame([
            'https://example.test/',
            'https://example.test/sale',
            'https://example.test/vehicle/traffic',
        ], collect($result['candidates'])->pluck('url')->all());
        $this->assertTrue($result['candidates'][0]['is_strategic']);
        $this->assertTrue($result['candidates'][2]['is_search_console']);
        $this->assertSame(1, $result['ignored_search_console_urls']);
        Http::assertNothingSent();
    }

    public function test_too_many_strategic_urls_fail_instead_of_being_truncated(): void
    {
        $this->configureSite([
            'strategic_urls' => ['https://example.test/a', 'https://example.test/b'],
            'max_urls' => 2,
        ]);

        $this->expectException(RuntimeException::class);
        app(SeoTechnicalUrlSelector::class)->select();
    }

    public function test_robots_sitemap_index_cycle_dedupe_and_membership_are_bounded(): void
    {
        $this->configureSite(['max_sitemap_documents' => 2]);
        $this->bindPublicDns();
        Http::fake([
            'https://example.test/robots.txt' => Http::response("User-agent: *\nSITEMAP: /sitemap-index.xml\nSitemap: /sitemap-index.xml", 200, ['Content-Type' => 'text/plain']),
            'https://example.test/sitemap-index.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.test/pages.xml</loc></sitemap><sitemap><loc>https://example.test/sitemap-index.xml</loc></sitemap></sitemapindex>',
                200,
                ['Content-Type' => 'application/xml'],
            ),
            'https://example.test/pages.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.test/</loc></url><url><loc>https://example.test/other</loc></url></urlset>',
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);
        $candidates = [
            ['url' => 'https://example.test/', 'url_hash' => app(SeoTechnicalUrlNormalizer::class)->hash('https://example.test/')],
            ['url' => 'https://example.test/missing', 'url_hash' => app(SeoTechnicalUrlNormalizer::class)->hash('https://example.test/missing')],
        ];

        $result = app(SeoTechnicalSitemapService::class)->inspect('https://example.test', $candidates);

        $this->assertSame(200, $result['robots_status']);
        $this->assertSame(1, $result['sitemap_sources']);
        $this->assertSame(2, $result['sitemap_documents_checked']);
        $this->assertTrue($result['sitemap_scan_complete']);
        $this->assertArrayHasKey($candidates[0]['url_hash'], $result['membership']);
        $this->assertArrayNotHasKey($candidates[1]['url_hash'], $result['membership']);
    }

    public function test_invalid_or_limited_sitemap_makes_not_found_membership_unknown(): void
    {
        $this->configureSite(['max_sitemap_documents' => 1]);
        $this->bindPublicDns();
        Http::fake([
            'https://example.test/robots.txt' => Http::response('Sitemap: https://example.test/index.xml', 200),
            'https://example.test/index.xml' => Http::response(
                '<sitemapindex><sitemap><loc>https://example.test/pages.xml</loc></sitemap></sitemapindex>',
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);
        $candidate = ['url' => 'https://example.test/missing', 'url_hash' => app(SeoTechnicalUrlNormalizer::class)->hash('https://example.test/missing')];

        $result = app(SeoTechnicalSitemapService::class)->inspect('https://example.test', [$candidate]);

        $this->assertFalse($result['sitemap_scan_complete']);
        $this->assertArrayNotHasKey($candidate['url_hash'], $result['membership']);
    }

    public function test_invalid_xml_is_partial_and_external_entities_are_never_expanded(): void
    {
        $this->configureSite(['sitemap_urls' => ['https://example.test/invalid.xml', 'https://example.test/xxe.xml']]);
        $this->bindPublicDns();
        Http::fake([
            'https://example.test/robots.txt' => Http::response('', 404),
            'https://example.test/invalid.xml' => Http::response('<urlset><url>', 200, ['Content-Type' => 'application/xml']),
            'https://example.test/xxe.xml' => Http::response(
                '<!DOCTYPE urlset [<!ENTITY secret SYSTEM "file:///etc/passwd">]><urlset><url><loc>&secret;</loc></url></urlset>',
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);
        $candidate = ['url' => 'https://example.test/', 'url_hash' => app(SeoTechnicalUrlNormalizer::class)->hash('https://example.test/')];

        $result = app(SeoTechnicalSitemapService::class)->inspect('https://example.test', [$candidate]);

        $this->assertFalse($result['sitemap_scan_complete']);
        $this->assertGreaterThanOrEqual(1, $result['sitemap_documents_failed']);
        $this->assertArrayNotHasKey($candidate['url_hash'], $result['membership']);
        Http::assertSentCount(3);
    }

    public function test_bounded_gzip_sitemap_is_supported_without_dependency(): void
    {
        $this->configureSite(['sitemap_urls' => ['https://example.test/pages.xml.gz']]);
        $this->bindPublicDns();
        $xml = '<urlset><url><loc>https://example.test/</loc></url></urlset>';
        Http::fake([
            'https://example.test/robots.txt' => Http::response('', 404),
            'https://example.test/pages.xml.gz' => Http::response(gzencode($xml), 200, ['Content-Type' => 'application/gzip']),
        ]);
        $candidate = ['url' => 'https://example.test/', 'url_hash' => app(SeoTechnicalUrlNormalizer::class)->hash('https://example.test/')];

        $result = app(SeoTechnicalSitemapService::class)->inspect('https://example.test', [$candidate]);

        $this->assertTrue($result['sitemap_scan_complete']);
        $this->assertArrayHasKey($candidate['url_hash'], $result['membership']);
    }

    public function test_configured_sitemap_does_not_make_failed_robots_discovery_complete(): void
    {
        $this->configureSite(['sitemap_urls' => ['https://example.test/pages.xml']]);
        $this->bindPublicDns();
        Http::fake([
            'https://example.test/robots.txt' => Http::response('', 500),
            'https://example.test/pages.xml' => Http::response(
                '<urlset><url><loc>https://example.test/found</loc></url></urlset>',
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);
        $candidates = $this->candidates('https://example.test/found', 'https://example.test/missing');

        $result = app(SeoTechnicalSitemapService::class)->inspect('https://example.test', $candidates);

        $this->assertFalse($result['sitemap_scan_complete']);
        $this->assertArrayHasKey($candidates[0]['url_hash'], $result['membership']);
        $this->assertArrayNotHasKey($candidates[1]['url_hash'], $result['membership']);
    }

    public function test_robots_404_is_conclusive_when_configured_sitemap_is_complete(): void
    {
        $this->configureSite(['sitemap_urls' => ['https://example.test/pages.xml']]);
        $this->bindPublicDns();
        Http::fake([
            'https://example.test/robots.txt' => Http::response('', 404),
            'https://example.test/pages.xml' => Http::response('<urlset></urlset>', 200, ['Content-Type' => 'application/xml']),
        ]);
        $candidate = $this->candidates('https://example.test/missing')[0];

        $result = app(SeoTechnicalSitemapService::class)->inspect('https://example.test', [$candidate]);

        $this->assertTrue($result['sitemap_scan_complete']);
        $this->assertArrayNotHasKey($candidate['url_hash'], $result['membership']);
    }

    public function test_truncated_robots_keeps_configured_sitemap_scan_partial(): void
    {
        $this->configureSite([
            'sitemap_urls' => ['https://example.test/pages.xml'],
            'max_robots_bytes' => 10,
        ]);
        $this->bindPublicDns();
        Http::fake([
            'https://example.test/robots.txt' => Http::response('User-agent: * Sitemap: /unknown.xml', 200),
            'https://example.test/pages.xml' => Http::response('<urlset></urlset>', 200, ['Content-Type' => 'application/xml']),
        ]);

        $truncated = app(SeoTechnicalSitemapService::class)->inspect(
            'https://example.test',
            $this->candidates('https://example.test/missing'),
        );

        $this->assertFalse($truncated['sitemap_scan_complete']);
        $this->assertSame('body_too_large', $truncated['robots_error_code']);
    }

    public function test_unreadable_robots_keeps_configured_sitemap_scan_partial(): void
    {
        $this->configureSite(['sitemap_urls' => ['https://example.test/pages.xml']]);
        $this->bindPublicDns();

        $stream = Mockery::mock(StreamInterface::class);
        $stream->shouldReceive('isSeekable')->once()->andReturnFalse();
        $stream->shouldReceive('eof')->once()->andReturnFalse();
        $stream->shouldReceive('read')->once()->andThrow(new RuntimeException('synthetic robots stream timeout'));
        $stream->shouldReceive('close')->once();
        Http::fake(function (Request $request) use ($stream) {
            return match ($request->url()) {
                'https://example.test/robots.txt' => Http::response($stream, 200, ['Content-Type' => 'text/plain']),
                default => Http::response('<urlset></urlset>', 200, ['Content-Type' => 'application/xml']),
            };
        });

        $unreadable = app(SeoTechnicalSitemapService::class)->inspect(
            'https://example.test',
            $this->candidates('https://example.test/missing'),
        );

        $this->assertFalse($unreadable['sitemap_scan_complete']);
        $this->assertSame('body_read_error', $unreadable['robots_error_code']);
    }

    public function test_blocked_robots_sitemap_directive_is_not_requested_and_makes_scan_partial(): void
    {
        $this->configureSite();
        $this->bindPublicDns();
        Http::fake([
            'https://example.test/robots.txt' => Http::response("Sitemap: https://example.test/pages.xml\nSitemap: https://evil.test/private.xml", 200),
            'https://example.test/pages.xml' => Http::response('<urlset></urlset>', 200, ['Content-Type' => 'application/xml']),
        ]);

        $result = app(SeoTechnicalSitemapService::class)->inspect(
            'https://example.test',
            $this->candidates('https://example.test/missing'),
        );

        $this->assertFalse($result['sitemap_scan_complete']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://evil.test/private.xml');
        Http::assertSentCount(2);
    }

    public function test_partial_scan_persists_positive_membership_and_keeps_missing_membership_unknown(): void
    {
        $this->configureSite([
            'strategic_urls' => ['https://example.test/missing'],
            'sitemap_urls' => ['https://example.test/pages.xml'],
        ]);
        $this->bindPublicDns();
        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://example.test/robots.txt' => Http::response('', 500),
                'https://example.test/pages.xml' => Http::response(
                    '<urlset><url><loc>https://example.test/</loc></url></urlset>',
                    200,
                    ['Content-Type' => 'application/xml'],
                ),
                default => Http::response('<title>Page</title>', 200, ['Content-Type' => 'text/html']),
            };
        });

        $result = app(SeoTechnicalHealthSyncService::class)->sync();

        $this->assertFalse($result['stats']['sitemap_scan_complete']);
        $this->assertTrue(SeoTechnicalUrl::query()->where('url', 'https://example.test/')->firstOrFail()->in_sitemap);
        $this->assertNull(SeoTechnicalUrl::query()->where('url', 'https://example.test/missing')->firstOrFail()->in_sitemap);
    }

    private function searchConsolePage(int $rank, string $url, int $clicks): void
    {
        SeoSearchConsoleDimensionMetric::query()->create([
            'property' => 'sc-domain:example.test', 'period_days' => 90,
            'period_start' => '2026-05-19', 'period_end' => '2026-08-16',
            'dimension_type' => 'page', 'country_scope' => 'ESP', 'rank' => $rank,
            'dimension_value' => $url, 'dimension_hash' => hash('sha256', $url),
            'clicks' => $clicks, 'impressions' => $clicks * 10,
            'source_timezone' => 'America/Los_Angeles', 'extracted_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function configureSite(array $overrides = []): void
    {
        config(['seo_analytics.technical_health' => array_replace(
            config('seo_analytics.technical_health'),
            ['site_url' => 'https://example.test', 'allowed_hosts' => [], 'strategic_urls' => [], 'sitemap_urls' => []],
            $overrides,
        )]);
    }

    private function bindPublicDns(): void
    {
        app()->instance(SeoTechnicalDnsResolver::class, new class extends SeoTechnicalDnsResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    /** @return array<int, array{url: string, url_hash: string}> */
    private function candidates(string ...$urls): array
    {
        $normalizer = app(SeoTechnicalUrlNormalizer::class);

        return array_map(fn (string $url): array => [
            'url' => $url,
            'url_hash' => $normalizer->hash($url),
        ], $urls);
    }
}
