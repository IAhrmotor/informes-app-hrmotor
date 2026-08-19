<?php

namespace App\Services\SeoAnalytics;

final class SeoTechnicalUrlChecker
{
    public function __construct(
        private readonly SeoTechnicalHttpClient $http,
        private readonly SeoTechnicalPageInspector $inspector,
    ) {}

    /** @return array<string, mixed> */
    public function check(string $url): array
    {
        return $this->inspector->inspect($this->http->fetch(
            $url,
            (int) config('seo_analytics.technical_health.max_html_bytes', 524288),
            'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
        ));
    }
}
