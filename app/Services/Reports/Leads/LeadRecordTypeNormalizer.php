<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Str;

class LeadRecordTypeNormalizer
{
    public const TASACION = 'tasacion';

    public const VENTA = 'venta';

    public const VENTA_CON_CAMBIO = 'venta_con_cambio';

    public const LEAD = 'lead';

    public const AYVENS = 'ayvens';

    private const ALIASES = [
        'tasacion' => self::TASACION,
        'venta' => self::VENTA,
        'venta con cambio' => self::VENTA_CON_CAMBIO,
        'venta cambio' => self::VENTA_CON_CAMBIO,
        'lead' => self::VENTA,
        'ayvens' => self::VENTA,
    ];

    /**
     * Returns the controlled canonical key for a Salesforce Lead RecordType.
     * Unknown values deliberately remain unclassified instead of silently
     * becoming part of a business filter.
     */
    public function normalize(mixed $value): ?string
    {
        $comparable = Str::of((string) $value)
            ->trim()
            ->lower()
            ->ascii()
            ->replaceMatches('/\s+/', ' ')
            ->toString();

        if ($comparable === '') {
            return null;
        }

        return self::ALIASES[$comparable] ?? null;
    }

    public function label(?string $normalized): ?string
    {
        return match ($normalized) {
            self::TASACION => 'Tasación',
            self::VENTA => 'Venta',
            self::VENTA_CON_CAMBIO => 'Venta con cambio',
            self::LEAD => 'Lead',
            self::AYVENS => 'Ayvens',
            default => null,
        };
    }

    /** @return list<string> */
    public function ventaFilterTypes(): array
    {
        // Legacy persisted values remain eligible until the historical
        // reprocessing command has converted them to the canonical key.
        return [self::VENTA, self::VENTA_CON_CAMBIO, self::LEAD, self::AYVENS];
    }
}
