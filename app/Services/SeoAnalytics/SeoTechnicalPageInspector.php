<?php

namespace App\Services\SeoAnalytics;

use DOMDocument;
use DOMXPath;
use Throwable;

final class SeoTechnicalPageInspector
{
    public function __construct(private readonly SeoTechnicalUrlNormalizer $normalizer) {}

    /** @param array<string, mixed> $fetch
     * @return array<string, mixed>
     */
    public function inspect(array $fetch): array
    {
        $status = $fetch['http_status'];
        $contentType = strtolower((string) ($fetch['content_type'] ?? ''));
        $isHtml = $status !== null && $status >= 200 && $status < 300
            && (str_starts_with($contentType, 'text/html') || str_starts_with($contentType, 'application/xhtml+xml'));
        $xRobots = $fetch['x_robots_tag'] ?? null;
        $xRobotsFull = $fetch['x_robots_tag_full'] ?? $xRobots;
        $bodyTruncated = (bool) ($fetch['body_truncated'] ?? false);
        $metaRobots = null;
        $canonicalUrl = null;
        $canonicalCount = 0;
        $canonicalMatchesFinal = null;
        $htmlNoindex = false;

        if ($isHtml) {
            [$metaRobots, $htmlNoindex, $canonicalUrl, $canonicalCount, $canonicalMatchesFinal] = $this->parseHtml(
                (string) ($fetch['body'] ?? ''),
                (string) $fetch['final_url'],
            );
        }

        $headerNoindex = $this->containsNoindex((string) $xRobotsFull);
        $hasNoindex = null;
        if ($htmlNoindex || $headerNoindex) {
            $hasNoindex = true;
        } elseif ($isHtml || $xRobots !== null) {
            $hasNoindex = $isHtml && $bodyTruncated ? null : false;
        }
        if ($bodyTruncated) {
            $canonicalMatchesFinal = null;
        }

        return [
            'final_url' => $fetch['final_url'],
            'final_url_hash' => $fetch['final_url'] ? $this->normalizer->hash($fetch['final_url']) : null,
            'http_status' => $status,
            'redirect_count' => $fetch['redirect_count'],
            'response_time_ms' => $fetch['response_time_ms'],
            'content_type' => $fetch['content_type'],
            'is_html' => $status === null ? null : $isHtml,
            'meta_robots' => $metaRobots,
            'x_robots_tag' => $xRobots,
            'has_noindex' => $hasNoindex,
            'canonical_url' => $canonicalUrl,
            'canonical_url_hash' => $canonicalUrl ? $this->normalizer->hash($canonicalUrl) : null,
            'canonical_count' => $canonicalCount,
            'canonical_matches_final' => $canonicalMatchesFinal,
            'body_truncated' => $bodyTruncated,
            'error_code' => $fetch['error_code'],
            'error_message' => $fetch['error_message'],
        ];
    }

    /** @return array{0: ?string, 1: bool, 2: ?string, 3: int, 4: ?bool} */
    private function parseHtml(string $body, string $finalUrl): array
    {
        if ($body === '') {
            return [null, false, null, 0, null];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($document);
            $robotsValues = [];
            foreach ($xpath->query('//meta[@name and @content]') ?: [] as $meta) {
                $name = strtolower(trim((string) $meta->attributes?->getNamedItem('name')?->nodeValue));
                if (in_array($name, ['robots', 'googlebot'], true)) {
                    $robotsValues[] = trim((string) $meta->attributes?->getNamedItem('content')?->nodeValue);
                }
            }
            $metaRobots = $robotsValues === [] ? null : mb_substr(implode(', ', $robotsValues), 0, 512);

            $canonicals = [];
            foreach ($xpath->query('//link[@rel and @href]') ?: [] as $link) {
                $relations = preg_split('/\s+/', strtolower(trim((string) $link->attributes?->getNamedItem('rel')?->nodeValue))) ?: [];
                if (in_array('canonical', $relations, true)) {
                    $canonicals[] = trim((string) $link->attributes?->getNamedItem('href')?->nodeValue);
                }
            }
            $canonicalCount = count($canonicals);
            $canonicalUrl = null;
            if ($canonicalCount > 0) {
                try {
                    $canonicalUrl = $this->normalizer->resolve($finalUrl, $canonicals[0]);
                } catch (Throwable) {
                    $canonicalUrl = null;
                }
            }
            $matches = $canonicalCount === 1 && $canonicalUrl !== null
                ? hash_equals($this->normalizer->hash($finalUrl), $this->normalizer->hash($canonicalUrl))
                : null;

            return [
                $metaRobots,
                collect($robotsValues)->contains(fn (string $value): bool => $this->containsNoindex($value)),
                $canonicalUrl,
                $canonicalCount,
                $matches,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function containsNoindex(string $value): bool
    {
        return preg_match('/(?:^|[\s,;])noindex(?:$|[\s,;])/i', $value) === 1;
    }
}
