<?php

namespace App\Services\Reports\Stock;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StockCatalogNormalizer
{
    public const DIMENSIONS = ['brand', 'model', 'segment', 'fuel', 'body', 'purchase_source'];

    private array $keyCache = [];

    private array $displayCache = [];

    private ?array $excludedTermKeys = null;

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
