<?php

namespace App\Services\SeoAnalytics;

final class BrandVariantParser
{
    /** @return array<int, string> */
    public static function parse(string $value): array
    {
        $variants = [];
        $seen = [];

        foreach (explode(',', $value) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === ''
                || preg_match('//u', $candidate) !== 1
                || preg_match('/[\p{L}\p{N}]/u', $candidate) !== 1
                || preg_match('/\p{C}/u', $candidate) === 1) {
                continue;
            }

            $key = mb_strtolower($candidate);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $variants[] = $candidate;
        }

        return $variants;
    }
}
