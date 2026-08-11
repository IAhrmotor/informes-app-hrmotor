<?php

namespace App\Services\Reports\Stock;

use App\Models\StockCatalogAlias;
use App\Models\StockCatalogValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StockCatalogNormalizer
{
    public const DIMENSIONS = ['brand', 'model', 'segment', 'fuel', 'body', 'purchase_source'];

    private array $keyCache = [];

    private array $displayCache = [];

    private ?array $excludedTermKeys = null;

    private array $canonicalCache = [];

    private array $officialCatalogCache = [];

    private array $aliasCatalogCache = [];

    public const FIELD_BY_DIMENSION = [
        'brand' => 'PRO_SEL_Marca__c', 'model' => 'PRO_TEX_Modelo__c', 'segment' => 'Segmento__c',
        'fuel' => 'PRO_SEL_Combustible__c', 'body' => 'PRO_SEL_Carroceria__c',
        'state' => 'PRO_SEL_Estado__c', 'purchase_source' => 'Procedencia_de_compra__c',
    ];

    public function canonicalize(string $dimension, mixed $value): array
    {
        $raw = $this->display($value);
        $normalized = $this->key($value);
        $field = self::FIELD_BY_DIMENSION[$dimension] ?? null;
        if ($raw === null || $field === null) {
            return ['raw' => $raw, 'normalized' => $normalized ?: null, 'canonical' => $raw, 'rule' => 'raw_no_salesforce_catalog'];
        }
        $cacheKey = $field.'|'.$normalized;
        if (isset($this->canonicalCache[$cacheKey])) {
            return $this->canonicalCache[$cacheKey];
        }
        $officialCatalog = $this->officialCatalogCache[$field] ??= StockCatalogValue::query()
            ->where('field_api_name', $field)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (StockCatalogValue $item): string => $this->key($item->api_value));
        $official = $officialCatalog->get($normalized)
            ?? $officialCatalog->first(fn (StockCatalogValue $item): bool => $this->key($item->label) === $normalized);

        $aliases = $this->aliasCatalogCache[$field] ??= StockCatalogAlias::query()
            ->where('field_api_name', $field)
            ->where('approval_status', StockCatalogAlias::APPROVAL_APPROVED)
            ->with('catalogValue')
            ->get()
            ->keyBy('normalized_key');
        $alias = $official ? null : $aliases->get($normalized);
        $aliasOfficial = $alias?->catalogValue?->is_active ? $alias->catalogValue : null;
        $canonical = $official?->api_value ?? $aliasOfficial?->api_value ?? $raw;
        $rule = $official ? 'salesforce_active_value' : ($aliasOfficial ? $alias->rule_name : 'unmapped_raw_value');

        return $this->canonicalCache[$cacheKey] = compact('raw', 'normalized', 'canonical', 'rule');
    }

    public function key(mixed $value): string
    {
        $raw = (string) $value;
        if (array_key_exists($raw, $this->keyCache)) {
            return $this->keyCache[$raw];
        }

        return $this->keyCache[$raw] = Str::of($raw)
            ->lower()
            ->ascii()
            ->replace(['.', ',', '_', '-', '/', '\\'], ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    public function display(mixed $value): ?string
    {
        $raw = (string) $value;
        if (array_key_exists($raw, $this->displayCache)) {
            return $this->displayCache[$raw];
        }

        $value = Str::of($raw)->squish()->toString();

        return $this->displayCache[$raw] = ($value !== '' ? $value : null);
    }

    public function isOperationalVehicle(object $vehicle, bool $snapshot = false): bool
    {
        foreach (self::DIMENSIONS as $dimension) {
            $field = $snapshot ? 'vehicle_'.$dimension : $dimension;
            if ($this->isExcludedValue($vehicle->{$field} ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function duplicateGroups(Collection $vehicles): Collection
    {
        return collect(self::DIMENSIONS)->flatMap(function (string $dimension) use ($vehicles): Collection {
            return $vehicles
                ->filter(fn ($vehicle): bool => filled($vehicle->{$dimension} ?? null))
                ->groupBy(fn ($vehicle): string => $this->key($vehicle->{$dimension}))
                ->map(function (Collection $rows, string $key) use ($dimension): ?array {
                    $rawValues = $rows
                        ->pluck($dimension)
                        ->map(fn ($value) => trim((string) $value))
                        ->filter()
                        ->unique()
                        ->values();
                    if ($rawValues->count() <= 1) {
                        return null;
                    }

                    return [
                        'dimension' => $dimension,
                        'normalized_key' => $key,
                        'raw_values' => $rawValues->all(),
                        'vehicles' => $rows->pluck('salesforce_id')->filter()->values()->all(),
                    ];
                })
                ->filter()
                ->values();
        })->values();
    }

    public function excludedVehicles(Collection $vehicles): Collection
    {
        return $vehicles
            ->reject(fn ($vehicle): bool => $this->isOperationalVehicle($vehicle))
            ->values();
    }

    private function isExcludedValue(mixed $value): bool
    {
        $key = $this->key($value);
        if ($key === '') {
            return false;
        }

        $this->excludedTermKeys ??= collect(config('stock.excluded_catalog_terms', []))
            ->map(fn ($term): string => $this->key($term))
            ->filter()
            ->values()
            ->all();

        foreach ($this->excludedTermKeys as $termKey) {
            if ($termKey !== '' && str_contains($key, $termKey)) {
                return true;
            }
        }

        return false;
    }
}
