<?php

namespace App\Services\SeoAnalytics;

use RuntimeException;

final class BrandQueryClassifier
{
    /** @param array<int, string>|null $variants */
    public function regex(?array $variants = null): string
    {
        $variants ??= config('seo_analytics.brand_variants', []);

        if ($variants === []) {
            throw new RuntimeException('No hay variantes de marca configuradas para Search Console.');
        }

        $escaped = array_map(
            static fn (string $variant): string => preg_quote(mb_strtolower($variant)),
            $variants,
        );
        $regex = '(?i)(?:'.implode('|', $escaped).')';

        if (strlen($regex) > (int) config('seo_analytics.brand_regex_max_length', 4096)) {
            throw new RuntimeException('La expresion de marca supera el limite configurado.');
        }

        return $regex;
    }

    /** @param array<int, string>|null $variants */
    public function classify(string $query, ?array $variants = null): string
    {
        $variants ??= config('seo_analytics.brand_variants', []);
        $query = mb_strtolower($query);

        foreach ($variants as $variant) {
            if ($variant !== '' && mb_stripos($query, mb_strtolower($variant)) !== false) {
                return 'brand';
            }
        }

        return 'non_brand';
    }
}
