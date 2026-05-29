<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Str;

class CampaignValueNormalizer
{
    private const INVALID_KEYS = [
        '',
        '-',
        '--',
        'none',
        'null',
        'not set',
        'undefined',
        'sin campana',
        'sin atribucion',
        'campa a adquirida c',
        'id adquirido c',
        'contenido adquirido c',
        'lea sel fuente origen c',
        'lea sel medio origen c',
    ];

    public function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    public function key(mixed $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replace(['_', '/', '\\'], [' ', ' ', ' '])
            ->replaceMatches('/[()]+/', '')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    public function compactKey(mixed $value): string
    {
        return Str::of($this->key($value))
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    public function flexibleCampaignKey(mixed $value): string
    {
        return Str::of($this->key($value))
            ->replaceMatches('/([a-z])([0-9])/', '$1 $2')
            ->replaceMatches('/([0-9])([a-z])/', '$1 $2')
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    public function isValidAttributionValue(mixed $value): bool
    {
        $clean = $this->clean($value);

        return $clean !== null && ! in_array($this->key($clean), self::INVALID_KEYS, true);
    }

    public function hasClearSalesforceAttribution(mixed ...$values): bool
    {
        foreach ($values as $value) {
            if ($this->isValidAttributionValue($value)) {
                return true;
            }
        }

        return false;
    }

    public function inferPlatform(mixed $source, mixed $medium, mixed $campaign): string
    {
        $haystack = $this->key(implode(' ', array_filter([
            (string) $source,
            (string) $medium,
            (string) $campaign,
        ])));

        if (str_contains($haystack, 'meta') || str_contains($haystack, 'facebook') || str_contains($haystack, 'instagram')) {
            return 'meta';
        }

        if (str_contains($haystack, 'google') || str_contains($haystack, 'adwords')) {
            return 'google_ads';
        }

        return 'unknown';
    }
}
