<?php

namespace Tests\Unit;

use App\Services\SeoAnalytics\SeoTechnicalDnsResolver;
use App\Services\SeoAnalytics\SeoTechnicalHttpClient;
use App\Services\SeoAnalytics\SeoTechnicalPageInspector;
use App\Services\SeoAnalytics\SeoTechnicalUrlGuard;
use App\Services\SeoAnalytics\SeoTechnicalUrlNormalizer;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response as LaravelResponse;
use Illuminate\Support\Facades\Http;
use Mockery;
use Psr\Http\Message\StreamInterface;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class SeoTechnicalUrlSecurityTest extends TestCase
{
    public function test_normalization_preserves_semantics_and_rejects_unsafe_urls(): void
    {
        $normalizer = app(SeoTechnicalUrlNormalizer::class);

        $this->assertSame('https://example.test/Path/?b=2&a=1', $normalizer->normalize('HTTPS://EXAMPLE.TEST:443/Path/?b=2&a=1#fragment'));
        $this->assertSame('http://example.test/path', $normalizer->normalize('http://EXAMPLE.TEST:80/path'));
        $this->assertSame('https://example.test/c?x=1', $normalizer->resolve('https://example.test/a/b', '../c?x=1'));

        foreach (['ftp://example.test/', 'https://user:pass@example.test/', 'https:///missing', 'javascript:alert(1)'] as $invalid) {
            try {
                $normalizer->normalize($invalid);
                $this->fail('Expected invalid URL rejection.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_guard_rejects_local_ip_metadata_host_wrong_port_and_unlisted_host(): void
    {
        $this->configureSite();
        $guard = app(SeoTechnicalUrlGuard::class);

        foreach ([
            'http://localhost/',
            'http://127.0.0.1/',
            'http://[::1]/',
            'http://169.254.169.254/latest/meta-data/',
            'https://evil.test/',
            'https://example.test:8443/',
        ] as $invalid) {
            try {
                $guard->assertAllowed($invalid);
                $this->fail('Expected SSRF guard rejection.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_guard_rejects_allowed_hostname_when_dns_resolves_to_private_space(): void
    {
        $this->configureSite();
        app()->instance(SeoTechnicalDnsResolver::class, new class extends SeoTechnicalDnsResolver
        {
            public function resolve(string $host): array
            {
                return ['10.0.0.5'];
            }
        });

        $this->expectException(RuntimeException::class);
        app(SeoTechnicalUrlGuard::class)->assertFetchable('https://example.test/');
    }

    public function test_guard_requires_every_dns_result_to_be_globally_routable(): void
    {
        $this->configureSite();
        foreach ([
            '10.0.0.5', '127.0.0.1', '169.254.169.254', '::1',
            '100.64.0.1', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1',
        ] as $address) {
            app()->instance(SeoTechnicalDnsResolver::class, new class($address) extends SeoTechnicalDnsResolver
            {
                public function __construct(private readonly string $address) {}

                public function resolve(string $host): array
                {
                    return [$this->address];
                }
            });

            try {
                app(SeoTechnicalUrlGuard::class)->assertFetchable('https://example.test/');
                $this->fail('Expected non-global DNS rejection for '.$address);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        app()->instance(SeoTechnicalDnsResolver::class, new class extends SeoTechnicalDnsResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34', '10.0.0.1'];
            }
        });
        $this->expectException(RuntimeException::class);
        app(SeoTechnicalUrlGuard::class)->assertFetchable('https://example.test/');
    }

    public function test_guard_allows_a_public_address_and_rejects_noncanonical_numeric_hosts(): void
    {
        $this->configureSite();
        $this->bindPublicDns();
        $this->assertSame('93.184.216.34', app(SeoTechnicalUrlGuard::class)->assertFetchable('https://example.test/')['ip']);

        foreach (['127.1', '2130706433', '0x7f000001', '0177.0.0.1', '127.0.0.%31', 'example.test.'] as $host) {
            config(['seo_analytics.technical_health.site_url' => 'https://'.$host.'/']);
            try {
                app(SeoTechnicalUrlGuard::class)->siteOrigin();
                $this->fail('Expected noncanonical host rejection for '.$host);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_relative_redirect_is_followed_manually(): void
    {
        $this->configureSite();
        $this->bindPublicDns();
        Http::fake([
            'https://example.test/start' => Http::response('', 301, ['Location' => '/final']),
            'https://example.test/final' => Http::response('ok', 200, ['Content-Type' => 'text/plain']),
        ]);

        $result = app(SeoTechnicalHttpClient::class)->fetch('https://example.test/start', 1024);
        $this->assertSame(200, $result['http_status']);
        $this->assertSame(1, $result['redirect_count']);
        $this->assertSame('https://example.test/final', $result['final_url']);
        Http::assertSentCount(2);
    }

    public function test_cross_host_redirect_is_blocked_before_requesting_destination(): void
    {
        $this->configureSite();
        $this->bindPublicDns();
        Http::fake(['https://example.test/start' => Http::response('', 301, ['Location' => 'https://evil.test/private'])]);
        $blocked = app(SeoTechnicalHttpClient::class)->fetch('https://example.test/start', 1024);
        $this->assertSame('blocked_redirect', $blocked['error_code']);
        Http::assertSentCount(1);
    }

    public function test_redirect_loop_is_bounded(): void
    {
        $this->configureSite();
        $this->bindPublicDns();
        Http::fake([
            'https://example.test/a' => Http::response('', 301, ['Location' => '/b']),
            'https://example.test/b' => Http::response('', 301, ['Location' => '/a']),
        ]);
        $loop = app(SeoTechnicalHttpClient::class)->fetch('https://example.test/a', 1024);
        $this->assertSame('redirect_loop', $loop['error_code']);
    }

    public function test_absolute_same_host_redirect_is_followed_and_redirect_limit_is_enforced(): void
    {
        $this->configureSite();
        $this->bindPublicDns();
        config(['seo_analytics.technical_health.max_redirects' => 1]);
        Http::fake([
            'https://example.test/a' => Http::response('', 301, ['Location' => 'https://example.test/b']),
            'https://example.test/b' => Http::response('', 302, ['Location' => '/c']),
            'https://example.test/c' => Http::response('must not be requested', 200),
        ]);

        $result = app(SeoTechnicalHttpClient::class)->fetch('https://example.test/a', 1024);

        $this->assertSame('redirect_limit', $result['error_code']);
        $this->assertSame(1, $result['redirect_count']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://example.test/c');
    }

    public function test_html_signals_and_body_limits_are_descriptive_only(): void
    {
        $inspector = app(SeoTechnicalPageInspector::class);
        $fetch = [
            'final_url' => 'https://example.test/page', 'http_status' => 200, 'redirect_count' => 0,
            'response_time_ms' => 12, 'content_type' => 'text/html; charset=UTF-8',
            'x_robots_tag' => 'index', 'body_truncated' => true, 'error_code' => null, 'error_message' => null,
            'body' => '<meta name="googlebot" content="index, noindex"><link rel="canonical" href="/canonical"><link rel="canonical alternate" href="/other">',
        ];
        $result = $inspector->inspect($fetch);

        $this->assertTrue($result['has_noindex']);
        $this->assertSame(2, $result['canonical_count']);
        $this->assertNull($result['canonical_matches_final']);
        $this->assertSame('https://example.test/canonical', $result['canonical_url']);
        $this->assertTrue($result['body_truncated']);

        $fetch['body'] = '<meta name="robots" content="index"><link rel="canonical" href="/page">';
        $fetch['body_truncated'] = false;
        $result = $inspector->inspect($fetch);
        $this->assertFalse($result['has_noindex']);
        $this->assertTrue($result['canonical_matches_final']);

        $fetch['content_type'] = 'application/pdf';
        $result = $inspector->inspect($fetch);
        $this->assertFalse($result['is_html']);
        $this->assertNull($result['canonical_url']);
    }

    public function test_http_body_is_bounded_and_x_robots_tag_is_evaluated(): void
    {
        $this->configureSite();
        $this->bindPublicDns();
        Http::fake([
            'https://example.test/large' => Http::response(str_repeat('x', 32), 200, [
                'Content-Type' => 'text/html',
                'X-Robots-Tag' => 'googlebot: noindex',
            ]),
        ]);

        $fetch = app(SeoTechnicalHttpClient::class)->fetch('https://example.test/large', 10);
        $this->assertSame(10, strlen($fetch['body']));
        $this->assertTrue($fetch['body_truncated']);
        $result = app(SeoTechnicalPageInspector::class)->inspect($fetch);
        $this->assertTrue($result['has_noindex']);
    }

    public function test_transport_is_direct_pinned_and_keeps_tls_verification_enabled(): void
    {
        $client = app(SeoTechnicalHttpClient::class);
        $method = new ReflectionMethod($client, 'transportOptions');
        $previousProxy = getenv('HTTPS_PROXY');
        putenv('HTTPS_PROXY=http://127.0.0.1:8888');
        try {
            $options = $method->invoke($client, [
                'url' => 'https://example.test/', 'host' => 'example.test', 'port' => 443, 'ip' => '93.184.216.34',
            ], '93.184.216.34');
        } finally {
            putenv($previousProxy === false ? 'HTTPS_PROXY' : 'HTTPS_PROXY='.$previousProxy);
        }

        $this->assertSame('', $options['proxy']);
        $this->assertTrue($options['verify']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['stream']);
        $this->assertSame(10, $options['read_timeout']);
        $this->assertSame(['example.test:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function test_bounded_body_reads_multiple_chunks_and_distinguishes_exact_limit(): void
    {
        $client = app(SeoTechnicalHttpClient::class);
        $method = new ReflectionMethod($client, 'boundedBody');
        $stream = Mockery::mock(StreamInterface::class);
        $stream->shouldReceive('isSeekable')->once()->andReturnFalse();
        $stream->shouldReceive('eof')->times(3)->andReturn(false, false, true);
        $stream->shouldReceive('read')->twice()->andReturn('abc', 'defg');
        $stream->shouldReceive('close')->once();

        [$chunked, $chunkedTruncated] = $method->invoke($client, new LaravelResponse(new PsrResponse(200, [], $stream)), 10);
        [$exact, $exactTruncated] = $method->invoke($client, new LaravelResponse(new PsrResponse(200, [], Utils::streamFor('1234567890'))), 10);
        [$limited, $limitedTruncated] = $method->invoke($client, new LaravelResponse(new PsrResponse(200, [], Utils::streamFor('12345678901'))), 10);

        $this->assertSame('abcdefg', $chunked);
        $this->assertFalse($chunkedTruncated);
        $this->assertSame('1234567890', $exact);
        $this->assertFalse($exactTruncated);
        $this->assertSame('1234567890', $limited);
        $this->assertTrue($limitedTruncated);
    }

    public function test_truncated_html_keeps_negative_signals_unknown_but_preserves_positive_findings(): void
    {
        $inspector = app(SeoTechnicalPageInspector::class);
        $base = [
            'final_url' => 'https://example.test/page', 'http_status' => 200, 'redirect_count' => 0,
            'response_time_ms' => 1, 'content_type' => 'text/html', 'x_robots_tag' => null,
            'body_truncated' => true, 'error_code' => null, 'error_message' => null,
        ];

        $unknown = $inspector->inspect($base + ['body' => '<meta name="robots" content="index">']);
        $oneCanonical = $inspector->inspect($base + ['body' => '<link rel="canonical" href="/page">']);
        $fullHeader = str_repeat('index,', 110).', noindex';
        $positive = $inspector->inspect(array_replace($base, [
            'x_robots_tag' => substr($fullHeader, 0, 512),
            'x_robots_tag_full' => $fullHeader,
            'body' => '<meta name="robots" content="noindex"><link rel="canonical" href="/a"><link rel="canonical" href="/b">',
        ]));

        $this->assertNull($unknown['has_noindex']);
        $this->assertSame(0, $unknown['canonical_count']);
        $this->assertNull($unknown['canonical_matches_final']);
        $this->assertSame(1, $oneCanonical['canonical_count']);
        $this->assertNull($oneCanonical['canonical_matches_final']);
        $this->assertTrue($positive['has_noindex']);
        $this->assertSame(2, $positive['canonical_count']);
        $this->assertNull($positive['canonical_matches_final']);
        $this->assertSame(512, strlen($positive['x_robots_tag']));
    }

    private function configureSite(): void
    {
        config([
            'seo_analytics.technical_health.site_url' => 'https://example.test/base',
            'seo_analytics.technical_health.allowed_hosts' => [],
        ]);
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
}
