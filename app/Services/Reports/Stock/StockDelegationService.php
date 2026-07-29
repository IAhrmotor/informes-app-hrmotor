<?php

namespace App\Services\Reports\Stock;

use App\Models\StockDelegation;

class StockDelegationService
{
    /** @var array<string, StockDelegation|null> */
    private array $salesforceCache = [];

    public function __construct(
        private readonly StockDelegationNormalizer $normalizer,
    ) {}

    public function resolveSalesforce(?string $salesforceId, ?string $salesforceName): ?StockDelegation
    {
        if (blank($salesforceId) && blank($salesforceName)) {
            return null;
        }

        $normalized = $this->normalizer->normalize($salesforceName);
        $cacheKey = filled($salesforceId)
            ? 'id:'.$salesforceId
            : 'name:'.$normalized['normalized_key'];
        if (array_key_exists($cacheKey, $this->salesforceCache)) {
            return $this->salesforceCache[$cacheKey];
        }

        $delegation = filled($salesforceId)
            ? StockDelegation::query()->where('salesforce_id', $salesforceId)->first()
            : null;

        $delegation ??= StockDelegation::query()
            ->where('normalized_key', $normalized['normalized_key'])
            ->first();

        $delegation ??= new StockDelegation;
        $attributes = [
            'canonical_name' => $normalized['canonical_name'],
            'normalized_key' => $normalized['normalized_key'],
            'commercial_group' => $normalized['commercial_group'],
            'zone' => $normalized['zone'],
        ];
        if (filled($salesforceId)) {
            $attributes['salesforce_id'] = $salesforceId;
        }
        if (filled($salesforceName) && (filled($salesforceId) || blank($delegation->salesforce_name))) {
            $attributes['salesforce_name'] = $salesforceName;
        }
        $delegation->fill($attributes);
        $delegation->save();

        return $this->salesforceCache[$cacheKey] = $delegation;
    }

    public function applyCapacity(string $sourceName, int $capacity): StockDelegation
    {
        $normalized = $this->normalizer->normalize($sourceName);
        $delegation = StockDelegation::query()
            ->where('normalized_key', $normalized['normalized_key'])
            ->firstOrNew();

        $delegation->fill([
            'canonical_name' => $normalized['canonical_name'],
            'normalized_key' => $normalized['normalized_key'],
            'commercial_group' => $normalized['commercial_group'],
            'zone' => $normalized['zone'],
            'capacity_total' => max($capacity, 0),
            'capacity_source_name' => $sourceName,
            'capacity_updated_at' => now(),
            'is_commercial' => true,
        ]);
        $delegation->save();
        $this->salesforceCache = [];

        return $delegation;
    }
}
