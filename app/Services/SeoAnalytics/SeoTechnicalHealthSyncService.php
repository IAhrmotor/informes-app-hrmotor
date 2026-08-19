<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoTechnicalUrl;
use App\Models\SeoTechnicalUrlCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SeoTechnicalHealthSyncService
{
    public const DATASET = 'seo_technical_health';

    public function __construct(
        private readonly SeoTechnicalUrlGuard $guard,
        private readonly SeoTechnicalUrlSelector $selector,
        private readonly SeoTechnicalSitemapService $sitemaps,
        private readonly SeoTechnicalUrlChecker $checker,
    ) {}

    public function configured(): bool
    {
        return $this->guard->configured();
    }

    public function siteHost(): string
    {
        return strtolower((string) parse_url($this->guard->siteOrigin(), PHP_URL_HOST));
    }

    /** @return array<string, mixed> */
    public function sync(?int $limit = null): array
    {
        $origin = $this->guard->siteOrigin();
        $selection = $this->selector->select($limit);
        $candidates = $selection['candidates'];
        $sitemap = $this->sitemaps->inspect($origin, $candidates);
        $checkedAt = CarbonImmutable::now((string) config('seo_analytics.timezone', 'Europe/Madrid'));
        $checkDate = $checkedAt->toDateString();
        $checks = [];

        foreach ($candidates as &$candidate) {
            $hash = $candidate['url_hash'];
            $candidate['in_sitemap'] = isset($sitemap['membership'][$hash])
                ? true
                : ($sitemap['sitemap_scan_complete'] === true ? false : null);
            $candidate['sitemap_url'] = $sitemap['membership'][$hash] ?? null;
            $checks[$hash] = $this->checker->check($candidate['url']);
        }
        unset($candidate);

        DB::transaction(function () use ($candidates, $checks, $checkedAt, $checkDate): void {
            SeoTechnicalUrl::query()->where('is_active', true)->update(['is_active' => false, 'updated_at' => $checkedAt]);
            $registryRows = array_map(fn (array $candidate): array => $candidate + [
                'is_active' => true,
                'first_selected_at' => $checkedAt,
                'last_selected_at' => $checkedAt,
                'sitemap_checked_at' => $checkedAt,
                'created_at' => $checkedAt,
                'updated_at' => $checkedAt,
            ], $candidates);
            foreach (array_chunk($registryRows, 250) as $chunk) {
                SeoTechnicalUrl::query()->upsert($chunk, ['url_hash'], [
                    'url', 'host', 'is_active', 'is_strategic', 'is_search_console',
                    'search_console_rank', 'search_console_clicks', 'search_console_impressions',
                    'in_sitemap', 'sitemap_url', 'last_selected_at', 'sitemap_checked_at', 'updated_at',
                ]);
            }

            $ids = SeoTechnicalUrl::query()
                ->whereIn('url_hash', array_column($candidates, 'url_hash'))
                ->pluck('id', 'url_hash');
            $checkRows = [];
            foreach ($checks as $hash => $check) {
                $checkRows[] = $check + [
                    'seo_technical_url_id' => $ids[$hash],
                    'check_date' => $checkDate,
                    'checked_at' => $checkedAt,
                    'created_at' => $checkedAt,
                    'updated_at' => $checkedAt,
                ];
            }
            foreach (array_chunk($checkRows, 250) as $chunk) {
                SeoTechnicalUrlCheck::query()->upsert($chunk, ['seo_technical_url_id', 'check_date'], [
                    'checked_at', 'final_url', 'final_url_hash', 'http_status', 'redirect_count',
                    'response_time_ms', 'content_type', 'is_html', 'meta_robots', 'x_robots_tag',
                    'has_noindex', 'canonical_url', 'canonical_url_hash', 'canonical_count',
                    'canonical_matches_final', 'body_truncated', 'error_code', 'error_message', 'updated_at',
                ]);
            }
        });

        $values = collect($checks);
        $stats = [
            'site_host' => $this->siteHost(),
            'check_date' => $checkDate,
            'robots_status' => $sitemap['robots_status'],
            'robots_error_code' => $sitemap['robots_error_code'],
            'sitemap_sources' => $sitemap['sitemap_sources'],
            'sitemap_documents_checked' => $sitemap['sitemap_documents_checked'],
            'sitemap_documents_failed' => $sitemap['sitemap_documents_failed'],
            'sitemap_urls_scanned' => $sitemap['sitemap_urls_scanned'],
            'sitemap_scan_complete' => $sitemap['sitemap_scan_complete'],
            'strategic_candidates' => $selection['strategic_candidates'],
            'search_console_candidates' => $selection['search_console_candidates'],
            'ignored_search_console_urls' => $selection['ignored_search_console_urls'],
            'selected_urls' => count($candidates),
            'checked_urls' => $values->count(),
            'http_2xx' => $values->filter(fn (array $row): bool => $this->statusBetween($row, 200, 299))->count(),
            'http_3xx_final' => $values->filter(fn (array $row): bool => $this->statusBetween($row, 300, 399))->count(),
            'http_4xx' => $values->filter(fn (array $row): bool => $this->statusBetween($row, 400, 499))->count(),
            'http_5xx' => $values->filter(fn (array $row): bool => $this->statusBetween($row, 500, 599))->count(),
            'redirected_urls' => $values->where('redirect_count', '>', 0)->count(),
            'network_errors' => $values->whereNotNull('error_code')->count(),
            'noindex_urls' => $values->where('has_noindex', true)->count(),
            'canonical_mismatch_urls' => $values->where('canonical_matches_final', false)->count(),
            'outside_sitemap_urls' => $sitemap['sitemap_scan_complete'] === true
                ? collect($candidates)->where('in_sitemap', false)->count()
                : 0,
        ];

        return ['checked_at' => $checkedAt, 'stats' => $stats];
    }

    private function statusBetween(array $row, int $minimum, int $maximum): bool
    {
        $status = $row['http_status'] ?? null;

        return is_int($status) && $status >= $minimum && $status <= $maximum;
    }
}
