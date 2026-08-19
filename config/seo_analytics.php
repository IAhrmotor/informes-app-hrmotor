<?php

use App\Services\SeoAnalytics\BrandVariantParser;

$defaultBrandVariants = 'hr motor,hrmotor,hr-motor,hrmotor.com';
$configuredBrandVariants = env('SEO_BRAND_VARIANTS');

return [
    'timezone' => 'Europe/Madrid',
    'primary_country' => 'ESP',
    'search_console_timezone' => 'America/Los_Angeles',
    'dashboard_ranges' => [7, 28, 90],
    'default_dashboard_range' => 28,
    'history_sync_days' => 120,
    'max_history_sync_days' => 480,
    'ga4_reporting_lag_days' => env('SEO_GA4_REPORTING_LAG_DAYS', 3),
    'brand_regex_max_length' => 4096,
    'dimension_limits' => [
        'query' => 1000,
        'page' => 1000,
        'country' => 100,
    ],
    'visible_dimension_limit' => 50,
    'visible_ga4_event_limit' => 50,
    'brand_variants' => BrandVariantParser::parse(
        filled($configuredBrandVariants) ? (string) $configuredBrandVariants : $defaultBrandVariants
    ),
    'http' => [
        'connect_timeout' => 5,
        'timeout' => 20,
    ],
    'technical_health' => [
        'site_url' => env('SEO_TECHNICAL_SITE_URL'),
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('SEO_TECHNICAL_ALLOWED_HOSTS', ''))))),
        'strategic_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('SEO_TECHNICAL_STRATEGIC_URLS', ''))))),
        'sitemap_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('SEO_TECHNICAL_SITEMAP_URLS', ''))))),
        'search_console_period_days' => 90,
        'search_console_url_limit' => 150,
        'max_urls' => 200,
        'hard_max_urls' => 500,
        'connect_timeout_seconds' => 3,
        'request_timeout_seconds' => 10,
        'max_redirects' => 5,
        'max_html_bytes' => 524288,
        'max_robots_bytes' => 262144,
        'max_sitemap_documents' => 50,
        'max_sitemap_urls_scanned' => 100000,
        'max_sitemap_bytes' => 10485760,
        'visible_url_limit' => 100,
        'user_agent' => 'HRMotor-SEO-Health/1.0',
    ],
];
