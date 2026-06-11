<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CampaignTypeResolver
{
    private const TASACION_SOURCE_KEYS = [
        'tasador landing search 1',
        'expiey leads geo tasacion',
        'expiey leads geo tasacion nuevas ubicaciones',
    ];

    private const TYPE_LABELS = [
        'venta' => 'Venta',
        'tasacion' => 'Tasacion',
        'exposicion' => 'Exposicion',
        'branding' => 'Branding',
        'otros' => 'Otros',
    ];

    public function __construct(
        private readonly CampaignValueNormalizer $normalizer,
    ) {
    }

    public function typeFor(mixed $platform, mixed $campaignId, mixed $campaignName): string
    {
        $mapped = $this->mappedType($platform, $campaignId, $campaignName);

        if ($mapped !== null) {
            return $mapped;
        }

        $nameKey = $this->normalizer->key($campaignName);

        if ($this->isExactTasador($campaignName)) {
            return 'otros';
        }

        if ($this->isTasacionNameKey($nameKey)) {
            return 'tasacion';
        }

        if (str_contains($nameKey, 'ventas') || str_contains($nameKey, 'venta')) {
            return 'venta';
        }

        if (str_contains($nameKey, 'visitas a la tienda') || str_contains($nameKey, 'pmax')) {
            return 'exposicion';
        }

        if (str_contains($nameKey, 'youtube') || str_contains($nameKey, 'video') || str_contains($nameKey, 'shorts') || str_contains($nameKey, 'display')) {
            return 'branding';
        }

        if (str_contains($nameKey, 'catalogo') || str_contains($nameKey, 'instantforms')) {
            return 'otros';
        }

        return 'otros';
    }

    public function shouldExclude(mixed $campaignName): bool
    {
        return $this->excludedReason($campaignName) !== null;
    }

    public function excludedReason(mixed $campaignName): ?string
    {
        if (! $this->normalizer->isValidAttributionValue($campaignName)) {
            return 'null_empty_none';
        }

        $nameKey = $this->normalizer->key($campaignName);

        if ($nameKey === 'tasador') {
            return 'tasador_exact';
        }

        if (str_contains($nameKey, 'ren2click')) {
            return 'ren2click';
        }

        if (str_contains($nameKey, 'hrrenting')) {
            return 'hrrenting';
        }

        return null;
    }

    public function isTasacion(mixed $platform, mixed $campaignId, mixed $campaignName): bool
    {
        return $this->typeFor($platform, $campaignId, $campaignName) === 'tasacion';
    }

    public function sourceCampaignType(mixed $campaignName): ?string
    {
        if ($this->shouldExclude($campaignName)) {
            return null;
        }

        return $this->isTasacionNameKey($this->normalizer->key($campaignName))
            ? 'tasacion'
            : 'venta';
    }

    public function isExactTasador(mixed $campaignName): bool
    {
        return $this->normalizer->key($campaignName) === 'tasador';
    }

    private function mappedType(mixed $platform, mixed $campaignId, mixed $campaignName): ?string
    {
        if (! Schema::hasTable('campaign_type_mappings')) {
            return null;
        }

        $platform = (string) $platform;
        $campaignId = (string) $campaignId;
        $campaignName = (string) $campaignName;
        $nameKey = $this->normalizer->key($campaignName);

        $rows = DB::table('campaign_type_mappings')
            ->where('active', true)
            ->where(function ($query) use ($platform): void {
                $query->whereNull('platform')->orWhere('platform', $platform);
            })
            ->get();

        foreach ($rows as $row) {
            if (filled($row->campaign_id) && (string) $row->campaign_id === $campaignId) {
                return $this->normalizeType($row->campaign_type);
            }

            if (filled($row->campaign_name) && $this->normalizer->key($row->campaign_name) === $nameKey) {
                return $this->normalizeType($row->campaign_type);
            }
        }

        return null;
    }

    private function normalizeType(mixed $type): string
    {
        $typeKey = $this->normalizer->key($type);

        return array_key_exists($typeKey, self::TYPE_LABELS) ? $typeKey : 'otros';
    }

    private function isTasacionNameKey(string $nameKey): bool
    {
        if ($nameKey === '') {
            return false;
        }

        if (in_array($nameKey, self::TASACION_SOURCE_KEYS, true)) {
            return true;
        }

        if (str_contains($nameKey, 'tasacion')) {
            return true;
        }

        return $nameKey !== 'tasador' && str_contains($nameKey, 'tasador');
    }
}
