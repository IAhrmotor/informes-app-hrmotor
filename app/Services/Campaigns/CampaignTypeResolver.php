<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CampaignTypeResolver
{
    private const TASACION_MANUAL_NAMES = [
        'tasador landing search 1',
        'expiey leads geo tasacion',
        'expiey leads geo tasacion nuevas ubicaciones',
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

        if (in_array($nameKey, self::TASACION_MANUAL_NAMES, true)) {
            return 'tasacion';
        }

        if (str_contains($nameKey, 'tasador') || str_contains($nameKey, 'tasacion')) {
            return 'tasacion';
        }

        return 'venta';
    }

    public function shouldExclude(mixed $campaignName): bool
    {
        $nameKey = $this->normalizer->key($campaignName);

        return str_contains($nameKey, 'ren2click')
            || str_contains($nameKey, 'hrrenting');
    }

    public function isTasacion(mixed $platform, mixed $campaignId, mixed $campaignName): bool
    {
        return $this->typeFor($platform, $campaignId, $campaignName) === 'tasacion';
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
            ->where(function ($query) use ($campaignId, $campaignName): void {
                $query
                    ->where(function ($byId) use ($campaignId): void {
                        $byId->whereNotNull('campaign_id')->where('campaign_id', $campaignId);
                    })
                    ->orWhere(function ($byName) use ($campaignName): void {
                        $byName->whereNotNull('campaign_name')->where('campaign_name', $campaignName);
                    });
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
        return $this->normalizer->key($type) === 'tasacion' ? 'tasacion' : 'venta';
    }
}
