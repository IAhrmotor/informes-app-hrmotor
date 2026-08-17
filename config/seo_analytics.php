<?php

use App\Services\SeoAnalytics\BrandVariantParser;

$defaultBrandVariants = 'hr motor,hrmotor,hr-motor,hrmotor.com';
$configuredBrandVariants = env('SEO_BRAND_VARIANTS');

return [
    'timezone' => 'Europe/Madrid',
    'primary_country' => 'ESP',
    'brand_variants' => BrandVariantParser::parse(
        filled($configuredBrandVariants) ? (string) $configuredBrandVariants : $defaultBrandVariants
    ),
    'http' => [
        'connect_timeout' => 5,
        'timeout' => 20,
    ],
];
