<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceVehicle;
use App\Models\StockDelegation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StockRecommendationService
{
    public function prepare(Collection $stock, Collection $sales, Collection $delegations): array
    {
        $stockStats = [];
        foreach ($stock as $vehicle) {
            $delegationId = (int) ($vehicle->stock_delegation_id ?? 0);
            if ($delegationId <= 0) {
                continue;
            }
            $stats = &$stockStats[$delegationId];
            $stats ??= ['total' => 0, 'model' => [], 'old_model' => [], 'similar' => []];
            $stats['total']++;
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
                'rotation_sum' => [], 'rotation_count' => [],
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
        }

        return [
            'delegations' => $delegations->keyBy('id'),
            'stock' => $stockStats,
            'sales' => $saleStats,
            'weights' => config('stock.recommendation_weights'),
        ];
    }

    public function recommend(SalesforceVehicle $vehicle, array $context, bool $excludeCurrent = true): array
    {
        $rows = [];
        foreach ($context['delegations'] as $delegation) {
            if (! $delegation->is_commercial || $delegation->capacity_total === null) {
                continue;
            }
            if ($excludeCurrent && (int) $vehicle->stock_delegation_id === (int) $delegation->id) {
                continue;
            }
            $profile = $this->profile($vehicle, $delegation, $context);
            if ($profile['free_capacity'] <= 0) {
                continue;
            }
            $rows[] = $profile;
        }

        return collect($rows)
            ->sortByDesc('score')
            ->take(3)
            ->values()
            ->all();
    }

    public function currentProfile(SalesforceVehicle $vehicle, array $context): ?array
    {
        $delegation = $context['delegations']->get($vehicle->stock_delegation_id);

        return $delegation ? $this->profile($vehicle, $delegation, $context) : null;
    }

    private function profile(SalesforceVehicle $vehicle, StockDelegation $delegation, array $context): array
    {
        $weights = $context['weights'];
        $stock = $context['stock'][$delegation->id] ?? ['total' => 0, 'model' => [], 'old_model' => [], 'similar' => []];
        $sales = $context['sales'][$delegation->id] ?? [
            'total' => 0, 'model' => [], 'brand' => [], 'segment' => [], 'fuel' => [], 'band' => [],
            'rotation_sum' => [], 'rotation_count' => [],
        ];
        $modelKey = $this->key($vehicle->model);
        $brandKey = $this->key($vehicle->brand);
        $segmentKey = $this->key($vehicle->segment);
        $fuelKey = $this->key($vehicle->fuel);
        $band = $this->priceBand($vehicle->sale_price);
        $bandKey = $this->key($band);
        $similarKey = $this->similarKey($vehicle);
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

        $score = ($modelSales * $weights['model_sale'])
            + ($brandSales * $weights['brand_sale'])
            + ($segmentSales * $weights['segment_sale'])
            + ($fuelSales * $weights['fuel_sale'])
            + ($bandSales * $weights['price_band_sale'])
            + (min($freeCapacity, 20) * $weights['free_capacity'])
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

        $reasons = [];
        $reasons[] = "{$modelSales} ".trim(($vehicle->brand ?? '').' '.($vehicle->model ?? 'modelo'))." vendidos en 120 días";
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
            'score' => round($score, 1),
            'model_sales' => $modelSales,
            'brand_sales' => $brandSales,
            'segment_sales' => $segmentSales,
            'fuel_sales' => $fuelSales,
            'band_sales' => $bandSales,
            'same_model_stock' => $sameModelStock,
            'similar_stock' => $similarStock,
            'old_same_model_stock' => $oldSameModelStock,
            'average_rotation' => $averageRotation,
            'free_capacity' => $freeCapacity,
            'has_history' => $hasHistory,
            'reasons' => $reasons,
        ];
    }

    public function priceBand(mixed $price): string
    {
        if (! is_numeric($price) || (float) $price < 0) {
            return 'Sin precio';
        }
        $lower = (int) floor((float) $price / 5000) * 5000;
        $upper = $lower + 5000;

        return number_format($lower, 0, '.', '.').'–'.number_format($upper, 0, '.', '.').' €';
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
        return Str::of((string) $value)->lower()->ascii()->squish()->toString();
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
