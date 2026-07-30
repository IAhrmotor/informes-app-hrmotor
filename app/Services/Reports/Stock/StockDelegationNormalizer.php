<?php

namespace App\Services\Reports\Stock;

use App\Services\Reports\Leads\LeadDelegationNormalizer;
use Illuminate\Support\Str;

class StockDelegationNormalizer
{
    private const NON_COMMERCIAL_KEYS = [
        'fdfgfg',
        'hr motor mantenimiento',
        'hr motor pendiente de entrar',
        'hr new cars fuera de stock',
    ];

    public function __construct(
        private readonly LeadDelegationNormalizer $leadDelegationNormalizer,
    ) {}

    public function normalize(?string $name): array
    {
        $raw = trim((string) $name);
        $lead = $this->leadDelegationNormalizer->normalize($raw);
        $classified = (bool) ($lead['is_classified'] ?? false);
        $canonical = $classified ? (string) $lead['delegation'] : ($raw !== '' ? $raw : 'Sin delegación');

        return [
            'raw' => $raw !== '' ? $raw : null,
            'canonical_name' => $canonical,
            'normalized_key' => $this->key($canonical),
            'commercial_group' => $classified ? ($lead['group'] ?? null) : null,
            'zone' => $classified ? ($lead['zone'] ?? null) : null,
            'is_known_name' => $classified,
            'is_commercial' => $this->isCommercial($raw !== '' ? $raw : $canonical),
        ];
    }

    public function isCommercial(?string $name): bool
    {
        return ! in_array($this->key((string) $name), self::NON_COMMERCIAL_KEYS, true);
    }

    /** @return array<int, string> */
    public function nonCommercialKeys(): array
    {
        return self::NON_COMMERCIAL_KEYS;
    }

    public function key(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replace(['.', ',', '_', '-', '/', '\\'], ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
