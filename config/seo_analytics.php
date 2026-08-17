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
    'brand_regex_max_length' => 4096,
    'dimension_limits' => [
        'query' => 1000,
        'page' => 1000,
        'country' => 100,
    ],
    'visible_dimension_limit' => 50,
    'brand_variants' => BrandVariantParser::parse(
        filled($configuredBrandVariants) ? (string) $configuredBrandVariants : $defaultBrandVariants
    ),
    'http' => [
        'connect_timeout' => 5,
        'timeout' => 20,
    ],
];
