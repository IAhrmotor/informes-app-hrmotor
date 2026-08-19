<?php

namespace App\Services\SeoAnalytics;

use DOMDocument;
use DOMXPath;
use Throwable;

final class SeoTechnicalSitemapService
{
    public function __construct(
        private readonly SeoTechnicalHttpClient $http,
        private readonly SeoTechnicalUrlGuard $guard,
        private readonly SeoTechnicalUrlNormalizer $normalizer,
    ) {}

    /** @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    public function inspect(string $origin, array $candidates): array
    {
        $robotsUrl = rtrim($origin, '/').'/robots.txt';
        $robots = $this->http->fetch(
            $robotsUrl,
            (int) config('seo_analytics.technical_health.max_robots_bytes', 262144),
            'text/plain,*/*;q=0.1',
        );
        $robotsDiscovery = $this->robotsSitemaps($robots);
        $sources = $robotsDiscovery['urls'];
        foreach ((array) config('seo_analytics.technical_health.sitemap_urls', []) as $configured) {
            $sources[] = $this->guard->assertAllowed((string) $configured);
        }
        $sources = array_values(array_unique($sources));
        $membership = [];
        $candidateHashes = collect($candidates)->pluck('url_hash')->flip()->all();
        $queue = $sources;
        $seenDocuments = [];
        $documentsChecked = 0;
        $documentsFailed = 0;
        $urlsScanned = 0;
        $complete = $sources === [] && $robotsDiscovery['complete']
            ? null
            : $robotsDiscovery['complete'];
        $maxDocuments = min(50, max(1, (int) config('seo_analytics.technical_health.max_sitemap_documents', 50)));
        $maxUrls = min(100000, max(1, (int) config('seo_analytics.technical_health.max_sitemap_urls_scanned', 100000)));

        while ($queue !== []) {
            $sitemapUrl = array_shift($queue);
            $documentHash = $this->normalizer->hash($sitemapUrl);
            if (isset($seenDocuments[$documentHash])) {
                continue;
            }
            if ($documentsChecked + $documentsFailed >= $maxDocuments) {
                $complete = false;

                break;
            }
            $seenDocuments[$documentHash] = true;

            $fetch = $this->http->fetch(
                $sitemapUrl,
                (int) config('seo_analytics.technical_health.max_sitemap_bytes', 10485760),
                'application/xml,text/xml,application/gzip,*/*;q=0.1',
            );
            if ($fetch['error_code'] !== null || ($fetch['http_status'] ?? 0) < 200 || $fetch['http_status'] >= 300 || $fetch['body_truncated']) {
                $documentsFailed++;
                $complete = false;

                continue;
            }

            $xml = $this->decodedXml((string) $fetch['body'], $sitemapUrl, (string) ($fetch['content_type'] ?? ''));
            if ($xml === null) {
                $documentsFailed++;
                $complete = false;

                continue;
            }
            $parsed = $this->parseXml($xml);
            if ($parsed === null) {
                $documentsFailed++;
                $complete = false;

                continue;
            }
            $documentsChecked++;

            foreach ($parsed['sitemaps'] as $reference) {
                try {
                    $queue[] = $this->guard->assertAllowed($this->normalizer->resolve($sitemapUrl, $reference));
                } catch (Throwable) {
                    $documentsFailed++;
                    $complete = false;
                }
            }
            foreach ($parsed['urls'] as $reference) {
                if ($urlsScanned >= $maxUrls) {
                    $complete = false;

                    break 2;
                }
                $urlsScanned++;
                try {
                    $candidateUrl = $this->guard->assertAllowed($this->normalizer->resolve($sitemapUrl, $reference));
                    $hash = $this->normalizer->hash($candidateUrl);
                    if (isset($candidateHashes[$hash]) && ! isset($membership[$hash])) {
                        $membership[$hash] = $sitemapUrl;
                    }
                } catch (Throwable) {
                    // URLs no autorizadas del sitemap no se solicitan ni forman parte del conjunto monitorizado.
                }
            }
        }

        return [
            'robots_url' => $robotsUrl,
            'robots_status' => $robots['http_status'],
            'robots_error_code' => $robots['error_code']
                ?? (($robots['body_truncated'] ?? false) ? 'body_too_large' : null),
            'sitemap_sources' => count($sources),
            'sitemap_documents_checked' => $documentsChecked,
            'sitemap_documents_failed' => $documentsFailed,
            'sitemap_urls_scanned' => $urlsScanned,
            'sitemap_scan_complete' => $complete,
            'membership' => $membership,
        ];
    }

    /** @param array<string, mixed> $robots
     * @return array{urls: array<int, string>, complete: bool}
     */
    private function robotsSitemaps(array $robots): array
    {
        $status = (int) ($robots['http_status'] ?? 0);
        if (in_array($status, [404, 410], true)) {
            return ['urls' => [], 'complete' => true];
        }
        if (($robots['error_code'] ?? null) !== null || ($robots['body_truncated'] ?? false)) {
            return ['urls' => [], 'complete' => false];
        }
        if ($status < 200 || $status >= 300) {
            return ['urls' => [], 'complete' => false];
        }

        $urls = [];
        $complete = true;
        foreach (preg_split('/\R/', (string) $robots['body']) ?: [] as $line) {
            if (preg_match('/^\s*sitemap\s*:\s*(\S+)\s*$/i', $line, $match) !== 1) {
                continue;
            }
            try {
                $urls[] = $this->guard->assertAllowed($this->normalizer->resolve((string) $robots['final_url'], $match[1]));
            } catch (Throwable) {
                // La directiva no se solicita, pero impide afirmar que el descubrimiento fue exhaustivo.
                $complete = false;
            }
        }

        return ['urls' => array_values(array_unique($urls)), 'complete' => $complete];
    }

    private function decodedXml(string $body, string $url, string $contentType): ?string
    {
        $isGzip = str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.gz')
            || str_contains(strtolower($contentType), 'gzip');
        if (! $isGzip) {
            return $body;
        }
        if (! function_exists('gzdecode')) {
            return null;
        }

        $limit = min(10485760, max(1, (int) config('seo_analytics.technical_health.max_sitemap_bytes', 10485760)));
        $decoded = @gzdecode($body, $limit + 1);

        return is_string($decoded) && strlen($decoded) <= $limit ? $decoded : null;
    }

    /** @return array{urls: array<int, string>, sitemaps: array<int, string>}|null */
    private function parseXml(string $xml): ?array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }
            $root = strtolower((string) $document->documentElement?->localName);
            if (! in_array($root, ['urlset', 'sitemapindex'], true)) {
                return null;
            }
            $xpath = new DOMXPath($document);
            $locations = [];
            foreach ($xpath->query('/*[local-name()="'.$root.'"]/*[local-name()="'.($root === 'urlset' ? 'url' : 'sitemap').'"]/*[local-name()="loc"]') ?: [] as $location) {
                $value = trim((string) $location->textContent);
                if ($value !== '') {
                    $locations[] = $value;
                }
            }

            return $root === 'urlset'
                ? ['urls' => $locations, 'sitemaps' => []]
                : ['urls' => [], 'sitemaps' => $locations];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
