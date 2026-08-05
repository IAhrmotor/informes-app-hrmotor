<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceVehicle;
use App\Models\StockDelegation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StockRecommendationService
{
    private array $keyCache = [];

    private array $priceBandCache = [];

    private array $recommendationCache = [];

    public function __construct(
        private readonly StockCatalogNormalizer $catalogNormalizer,
    ) {}

    public function prepare(Collection $stock, Collection $sales, Collection $delegations): array
    {
        $this->recommendationCache = [];
        $stockStats = [];
        foreach ($stock as $vehicle) {
            $delegationId = (int) ($vehicle->stock_delegation_id ?? 0);
            if ($delegationId <= 0) {
                continue;
            }
            $stats = &$stockStats[$delegationId];
            $stats ??= ['total' => 0, 'model' => [], 'old_model' => [], 'similar' => []];
            $stats['total']++;
            if (! $this->catalogNormalizer->isOperationalVehicle($vehicle)) {
                continue;
            }
            $model = $this->key($vehicle->model);
            $similar = $this->similarKey($vehicle);
            $stats['model'][$model] = ($stats['model'][$model] ?? 0) + ($model !== '' ? 1 : 0);
            $stats['similar'][$similar] = ($stats['similar'][$similar] ?? 0) + ($similar !== '||' ? 1 : 0);
            if ($model !== '' && $this->age($vehicle) >= 60) {
                $stats['old_model'][$model] = ($stats['old_model'][$model] ?? 0) + 1;
            }
        }

        $saleStats = [];
        foreach ($sales as $sale) {
            $delegationId = (int) ($sale->stock_delegation_id ?? 0);
            if ($delegationId <= 0) {
                continue;
            }
            $stats = &$saleStats[$delegationId];
            $stats ??= [
                'total' => 0, 'model' => [], 'brand' => [], 'segment' => [], 'fuel' => [], 'band' => [],
                'rotation_sum' => [], 'rotation_count' => [], 'mileage_sum' => [], 'mileage_count' => [],
            ];
            $stats['total']++;
            foreach ([
                'model' => $sale->vehicle_model,
                'brand' => $sale->vehicle_brand,
                'segment' => $sale->vehicle_segment,
                'fuel' => $sale->vehicle_fuel,
                'band' => $this->priceBand($sale->sale_price),
            ] as $dimension => $value) {
                $key = $this->key($value);
                if ($key !== '') {
                    $stats[$dimension][$key] = ($stats[$dimension][$key] ?? 0) + 1;
                }
            }
            $model = $this->key($sale->vehicle_model);
            if ($sale->rotation_days !== null && $model !== '') {
                $stats['rotation_sum'][$model] = ($stats['rotation_sum'][$model] ?? 0) + (int) $sale->rotation_days;
                $stats['rotation_count'][$model] = ($stats['rotation_count'][$model] ?? 0) + 1;
            }
            if ($sale->vehicle_mileage !== null && $model !== '') {
                $stats['mileage_sum'][$model] = ($stats['mileage_sum'][$model] ?? 0) + (int) $sale->vehicle_mileage;
                $stats['mileage_count'][$model] = ($stats['mileage_count'][$model] ?? 0) + 1;
            }
        }

        $excludedDestinations = array_map(
            fn ($value): string => $this->key($value),
            config('stock.excluded_destination_keys', []),
        );
        $rankingDelegations = $delegations->filter(function (StockDelegation $delegation) use ($excludedDestinations): bool {
            if (
                ! $delegation->is_commercial
                || $delegation->capacity_total === null
                || (int) $delegation->capacity_total <= 0
                || in_array($this->key($delegation->canonical_name), $excludedDestinations, true)
            ) {
                return false;
            }

            return true;
        })->values();

        return [
            'delegations' => $delegations->keyBy('id'),
            'ranking_delegations' => $rankingDelegations,
            'eligible_delegations' => $rankingDelegations,
            'stock' => $stockStats,
            'sales' => $saleStats,
            'weights' => config('stock.recommendation_weights'),
        ];
    }

    public function recommend(SalesforceVehicle $vehicle, array $context, bool $excludeCurrent = true, bool $compact = false, ?int $limit = 3): array
    {
        $vehicleKeys = $this->vehicleKeys($vehicle);
        $cacheKey = $vehicleKeys['signature'].'|'.($excludeCurrent ? (int) $vehicle->stock_delegation_id : 0).'|'.($compact ? 'compact' : 'full').'|'.($limit ?? 'all');
        if (array_key_exists($cacheKey, $this->recommendationCache)) {
            return $this->recommendationCache[$cacheKey];
        }

        $rows = [];
        foreach ($context['ranking_delegations'] as $delegation) {
            if ($excludeCurrent && (int) $vehicle->stock_delegation_id === (int) $delegation->id) {
                continue;
            }
            $rows[] = $this->profile($vehicleKeys, $delegation, $context, $compact);
        }

        usort($rows, function (array $left, array $right): int {
            foreach (['brand_sales', 'model_sales', 'fuel_sales', 'mileage_similarity'] as $dimension) {
                $comparison = ($right[$dimension] ?? 0) <=> ($left[$dimension] ?? 0);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return $right['score'] <=> $left['score'];
        });

        return $this->recommendationCache[$cacheKey] = $limit === null ? $rows : array_slice($rows, 0, $limit);
    }

    public function currentProfile(SalesforceVehicle $vehicle, array $context, bool $compact = false): ?array
    {
        $delegation = $context['delegations']->get($vehicle->stock_delegation_id);

        return $delegation ? $this->profile($this->vehicleKeys($vehicle), $delegation, $context, $compact) : null;
    }

    private function profile(array $vehicleKeys, StockDelegation $delegation, array $context, bool $compact = false): array
    {
        $weights = $context['weights'];
        $stock = $context['stock'][$delegation->id] ?? ['total' => 0, 'model' => [], 'old_model' => [], 'similar' => []];
        $sales = $context['sales'][$delegation->id] ?? [
            'total' => 0, 'model' => [], 'brand' => [], 'segment' => [], 'fuel' => [], 'band' => [],
            'rotation_sum' => [], 'rotation_count' => [], 'mileage_sum' => [], 'mileage_count' => [],
        ];
        $modelKey = $vehicleKeys['model'];
        $brandKey = $vehicleKeys['brand'];
        $segmentKey = $vehicleKeys['segment'];
        $fuelKey = $vehicleKeys['fuel'];
        $band = $vehicleKeys['band'];
        $bandKey = $vehicleKeys['band_key'];
        $similarKey = $vehicleKeys['similar'];
        $modelSales = $sales['model'][$modelKey] ?? 0;
        $brandSales = $sales['brand'][$brandKey] ?? 0;
        $segmentSales = $sales['segment'][$segmentKey] ?? 0;
        $fuelSales = $sales['fuel'][$fuelKey] ?? 0;
        $bandSales = $sales['band'][$bandKey] ?? 0;
        $sameModelStock = $stock['model'][$modelKey] ?? 0;
        $oldSameModelStock = $stock['old_model'][$modelKey] ?? 0;
        $similarStock = $stock['similar'][$similarKey] ?? 0;
        $freeCapacity = max((int) $delegation->capacity_total - (int) $stock['total'], 0);
        $rotationCount = $sales['rotation_count'][$modelKey] ?? 0;
        $averageRotation = $rotationCount > 0
            ? round(($sales['rotation_sum'][$modelKey] ?? 0) / $rotationCount, 1)
            : null;
        $mileageCount = $sales['mileage_count'][$modelKey] ?? 0;
        $averageMileage = $mileageCount > 0
            ? round(($sales['mileage_sum'][$modelKey] ?? 0) / $mileageCount)
            : null;
        $vehicleMileage = $vehicleKeys['mileage'];
        $mileageSimilarity = $averageMileage !== null && $vehicleMileage !== null
            ? max(0, 100 - min(abs($averageMileage - $vehicleMileage) / 1000, 100))
            : 0;

        $score = ($modelSales * $weights['model_sale'])
            + ($brandSales * $weights['brand_sale'])
            + ($segmentSales * $weights['segment_sale'])
            + ($fuelSales * $weights['fuel_sale'])
            + ($bandSales * $weights['price_band_sale'])
            - ($sameModelStock * $weights['same_model_stock'])
            - ($oldSameModelStock * $weights['old_same_model_stock'])
            - ($similarStock * $weights['similar_stock']);
        if ($averageRotation !== null) {
            $score += max(0, (120 - min($averageRotation, 120)) / 120) * $weights['fast_rotation'];
        }
        $hasHistory = ($modelSales + $brandSales + $segmentSales + $fuelSales + $bandSales) > 0;
        if (! $hasHistory) {
            $score -= $weights['no_history'];
        }
        $roundedScore = round($score, 1);
        if ($compact) {
            return [
                'delegation_id' => $delegation->id,
                'score' => $roundedScore,
                'brand_sales' => $brandSales,
                'model_sales' => $modelSales,
                'fuel_sales' => $fuelSales,
                'mileage_similarity' => round($mileageSimilarity, 1),
                'free_capacity' => $freeCapacity,
                'is_executable' => $freeCapacity > 0,
            ];
        }

        $reasons = [];
        $reasons[] = "{$modelSales} {$vehicleKeys['label']} vendidos en 120 días";
        $reasons[] = $averageRotation !== null
            ? "Rotación media del modelo: {$averageRotation} días"
            : 'Sin rotación histórica suficiente del modelo';
        $reasons[] = "{$sameModelStock} unidades del mismo modelo actualmente";
        $reasons[] = "{$freeCapacity} plazas disponibles";
        if ($segmentSales > 0 || $bandSales > 0) {
            $reasons[] = "{$segmentSales} ventas del segmento y {$bandSales} del tramo {$band}";
        } elseif (! $hasHistory) {
            $reasons[] = 'Alternativa penalizada por no tener histórico comparable';
        }
        if ($oldSameModelStock > 0) {
            $reasons[] = "{$oldSameModelStock} unidades antiguas del mismo modelo penalizan el destino";
        }

        return [
            'delegation_id' => $delegation->id,
            'delegation' => $delegation->canonical_name,
            'score' => $roundedScore,
            'model_sales' => $modelSales,
            'brand_sales' => $brandSales,
            'segment_sales' => $segmentSales,
            'fuel_sales' => $fuelSales,
            'band_sales' => $bandSales,
            'same_model_stock' => $sameModelStock,
            'similar_stock' => $similarStock,
            'old_same_model_stock' => $oldSameModelStock,
            'average_rotation' => $averageRotation,
            'average_mileage' => $averageMileage,
            'mileage_similarity' => round($mileageSimilarity, 1),
            'free_capacity' => $freeCapacity,
            'is_executable' => $freeCapacity > 0,
            'capacity_excess' => max(((int) $stock['total'] + 1) - (int) $delegation->capacity_total, 0),
            'places_to_release' => max(((int) $stock['total'] + 1) - (int) $delegation->capacity_total, 0),
            'has_history' => $hasHistory,
            'reasons' => $reasons,
        ];
    }

    public function priceBand(mixed $price): string
    {
        $cacheKey = is_scalar($price) || $price === null ? (string) $price : serialize($price);
        if (array_key_exists($cacheKey, $this->priceBandCache)) {
            return $this->priceBandCache[$cacheKey];
        }
        if (! is_numeric($price) || (float) $price < 0) {
            return $this->priceBandCache[$cacheKey] = 'Sin precio';
        }
        $lower = (int) floor((float) $price / 5000) * 5000;
        $upper = $lower + 5000;

        return $this->priceBandCache[$cacheKey] = number_format($lower, 0, '.', '.').'–'.number_format($upper, 0, '.', '.').' €';
    }

    public function mileageBand(mixed $mileage): string
    {
        if (! is_numeric($mileage)) {
            return 'Sin kilómetros';
        }
        $value = (int) $mileage;
        foreach ([[0, 25000], [25000, 50000], [50000, 75000], [75000, 100000], [100000, 150000], [150000, 200000]] as [$lower, $upper]) {
            if ($value < $upper) {
                return number_format($lower, 0, '.', '.').'–'.number_format($upper, 0, '.', '.').' km';
            }
        }

        return 'Más de 200.000 km';
    }

    public function ageBand(?int $days): string
    {
        if ($days === null) {
            return 'Sin fecha';
        }
        return match (true) {
            $days < 30 => '0–30 días',
            $days < 60 => '30–60 días',
            $days < 90 => '60–90 días',
            $days < 120 => '90–120 días',
            $days < 180 => '120–180 días',
            default => 'Más de 180 días',
        };
    }

    public function age(SalesforceVehicle $vehicle): ?int
    {
        return $vehicle->entry_date ? (int) $vehicle->entry_date->diffInDays(now()) : null;
    }

    public function key(mixed $value): string
    {
        $raw = (string) $value;
        if (array_key_exists($raw, $this->keyCache)) {
            return $this->keyCache[$raw];
        }

        return $this->keyCache[$raw] = Str::of($raw)->lower()->ascii()->squish()->toString();
    }

    private function vehicleKeys(object $vehicle): array
    {
        $band = $this->priceBand($vehicle->sale_price ?? null);
        $keys = [
            'model' => $this->key($vehicle->model ?? null),
            'brand' => $this->key($vehicle->brand ?? null),
            'segment' => $this->key($vehicle->segment ?? null),
            'fuel' => $this->key($vehicle->fuel ?? null),
            'band' => $band,
            'band_key' => $this->key($band),
            'label' => trim(($vehicle->brand ?? '').' '.($vehicle->model ?? 'modelo')),
            'mileage' => is_numeric($vehicle->mileage ?? null) ? (int) $vehicle->mileage : null,
        ];
        $keys['similar'] = implode('|', [$keys['segment'], $keys['fuel'], $keys['band_key']]);
        $keys['signature'] = implode('|', [
            $keys['model'], $keys['brand'], $keys['segment'], $keys['fuel'], $keys['band_key'], $keys['label'],
        ]);

        return $keys;
    }

    private function similarKey(object $vehicle): string
    {
        return implode('|', [
            $this->key($vehicle->segment ?? null),
            $this->key($vehicle->fuel ?? null),
            $this->key($this->priceBand($vehicle->sale_price ?? null)),
        ]);
    }
}
