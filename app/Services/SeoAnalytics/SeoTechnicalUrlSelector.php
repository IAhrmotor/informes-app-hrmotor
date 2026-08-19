<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoSearchConsoleDimensionMetric;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class SeoTechnicalUrlSelector
{
    public function __construct(
        private readonly SearchConsoleClient $searchConsole,
        private readonly SeoTechnicalUrlGuard $guard,
        private readonly SeoTechnicalUrlNormalizer $normalizer,
    ) {}

    /** @return array{candidates: array<int, array<string, mixed>>, strategic_candidates: int, search_console_candidates: int, ignored_search_console_urls: int} */
    public function select(?int $requestedLimit = null): array
    {
        $limit = $this->validatedLimit($requestedLimit);
        $candidates = [];
        $origin = $this->guard->siteOrigin();
        $this->mergeCandidate($candidates, rtrim($origin, '/').'/', true, false);

        foreach ((array) config('seo_analytics.technical_health.strategic_urls', []) as $url) {
            $this->mergeCandidate($candidates, $this->guard->assertAllowed((string) $url), true, false);
        }
        $strategicCount = count($candidates);
        if ($strategicCount > $limit) {
            throw new RuntimeException('Las URLs estrategicas superan el limite tecnico configurado.');
        }

        $ignored = 0;
        $searchCount = 0;
        $property = $this->searchConsole->configuredProperty();
        if ($property) {
            $periodDays = (int) config('seo_analytics.technical_health.search_console_period_days', 90);
            $periodEnd = SeoSearchConsoleDimensionMetric::query()
                ->where('property', $property)
                ->where('period_days', $periodDays)
                ->where('dimension_type', 'page')
                ->where('country_scope', 'ESP')
                ->max('period_end');
            if ($periodEnd) {
                $periodEnd = CarbonImmutable::parse($periodEnd)->toDateString();
                $rows = SeoSearchConsoleDimensionMetric::query()
                    ->where('property', $property)
                    ->where('period_days', $periodDays)
                    ->where('dimension_type', 'page')
                    ->where('country_scope', 'ESP')
                    ->whereDate('period_end', $periodEnd)
                    ->orderBy('rank')
                    ->limit(min(150, (int) config('seo_analytics.technical_health.search_console_url_limit', 150)))
                    ->get();
                foreach ($rows as $row) {
                    try {
                        $url = $this->guard->assertAllowed((string) $row->dimension_value);
                    } catch (Throwable) {
                        $ignored++;

                        continue;
                    }
                    $hash = $this->normalizer->hash($url);
                    $wasSearchConsole = (bool) ($candidates[$hash]['is_search_console'] ?? false);
                    $this->mergeCandidate($candidates, $url, false, true, [
                        'search_console_rank' => $row->rank,
                        'search_console_clicks' => $row->clicks,
                        'search_console_impressions' => $row->impressions,
                    ]);
                    if (! $wasSearchConsole) {
                        $searchCount++;
                    }
                    if (count($candidates) >= $limit) {
                        break;
                    }
                }
            }
        }

        return [
            'candidates' => array_values($candidates),
            'strategic_candidates' => $strategicCount,
            'search_console_candidates' => $searchCount,
            'ignored_search_console_urls' => $ignored,
        ];
    }

    /** @param array<string, array<string, mixed>> $candidates
     * @param  array<string, mixed>  $metadata
     */
    private function mergeCandidate(
        array &$candidates,
        string $url,
        bool $strategic,
        bool $searchConsole,
        array $metadata = [],
    ): void {
        $normalized = $this->guard->assertAllowed($url);
        $hash = $this->normalizer->hash($normalized);
        $existing = $candidates[$hash] ?? [];
        $candidates[$hash] = [
            'url' => $normalized,
            'url_hash' => $hash,
            'host' => strtolower((string) parse_url($normalized, PHP_URL_HOST)),
            'is_strategic' => $strategic || (bool) ($existing['is_strategic'] ?? false),
            'is_search_console' => $searchConsole || (bool) ($existing['is_search_console'] ?? false),
            'search_console_rank' => $metadata['search_console_rank'] ?? $existing['search_console_rank'] ?? null,
            'search_console_clicks' => $metadata['search_console_clicks'] ?? $existing['search_console_clicks'] ?? null,
            'search_console_impressions' => $metadata['search_console_impressions'] ?? $existing['search_console_impressions'] ?? null,
        ];
    }

    private function validatedLimit(?int $requested): int
    {
        $hardCap = 500;
        $configured = (int) config('seo_analytics.technical_health.max_urls', 200);
        if ($configured < 1 || $configured > $hardCap) {
            throw new RuntimeException('technical_health.max_urls debe estar entre 1 y 500.');
        }
        if ($requested !== null && ($requested < 1 || $requested > $hardCap)) {
            throw new RuntimeException('--limit debe estar entre 1 y 500.');
        }

        return min($configured, $requested ?? $configured);
    }
}
