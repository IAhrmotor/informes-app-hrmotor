<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceVehicle;
use App\Models\StockDelegation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class StockDirectedTransferPlanner
{
    private const PRIORITY_ORDER = [
        'normal' => 0,
        'review' => 1,
        'priority' => 2,
    ];

    public function __construct(
        private readonly StockRecommendationService $recommendations,
        private readonly StockCatalogNormalizer $catalogNormalizer,
    ) {}

    public function plan(
        Collection $allStock,
        array $context,
        int $originId,
        int $destinationId,
        int $requestedUnits,
        CarbonImmutable $today,
        ?Collection $sameModelCounts = null,
    ): ?array {
        $origin = $context['delegations']->get($originId);
        $destination = $context['directed_delegations']->get($destinationId);

        if (
            ! $origin instanceof StockDelegation
            || ! $destination instanceof StockDelegation
            || $originId === $destinationId
            || $requestedUnits <= 0
        ) {
            return null;
        }

        $sameModelCounts ??= $allStock
            ->groupBy(fn (SalesforceVehicle $vehicle): string => $vehicle->stock_delegation_id.'|'.$this->recommendations->key($vehicle->model))
            ->map->count();

        $rows = $allStock
            ->filter(fn (SalesforceVehicle $vehicle): bool => $vehicle->is_in_stock
                && $vehicle->state === 'Disponible'
                && (int) $vehicle->stock_delegation_id === $originId
                && $this->catalogNormalizer->isOperationalVehicle($vehicle))
            ->map(function (SalesforceVehicle $vehicle) use ($context, $destination, $sameModelCounts, $today): array {
                $destinationProfile = $this->recommendations->evaluateDestination($vehicle, $destination, $context);
                $currentProfile = $this->recommendations->currentProfile($vehicle, $context);
                $days = $vehicle->entry_date ? (int) $vehicle->entry_date->diffInDays($today) : null;
                $sameModelStock = (int) $sameModelCounts->get(
                    $vehicle->stock_delegation_id.'|'.$this->recommendations->key($vehicle->model),
                    0,
                );
                $reviewLevel = $this->recommendations->reviewLevel(
                    $days,
                    $sameModelStock,
                    $currentProfile,
                    $destinationProfile,
                );
                $stableIdentifier = filled($vehicle->plate)
                    ? (string) $vehicle->plate
                    : ((string) ($vehicle->salesforce_id ?: $vehicle->id));

                return [
                    'id' => $vehicle->salesforce_id ?: (string) $vehicle->id,
                    'plate' => $vehicle->plate,
                    'vehicle' => trim(implode(' ', array_filter([
                        $vehicle->brand,
                        $vehicle->model,
                        $vehicle->version,
                    ]))),
                    'days' => $days,
                    'origin' => $vehicle->delegation?->canonical_name,
                    'review_level' => $reviewLevel,
                    'score' => $destinationProfile['score'],
                    'reasons' => $destinationProfile['commercial_reasons'],
                    'has_history' => $destinationProfile['has_history'],
                    '_stable_identifier' => mb_strtolower($stableIdentifier),
                    '_local_id' => (int) $vehicle->id,
                ];
            })
            ->sort(function (array $left, array $right): int {
                $comparison = $right['score'] <=> $left['score'];
                if ($comparison !== 0) {
                    return $comparison;
                }

                $comparison = (self::PRIORITY_ORDER[$right['review_level']] ?? 0)
                    <=> (self::PRIORITY_ORDER[$left['review_level']] ?? 0);
                if ($comparison !== 0) {
                    return $comparison;
                }

                $comparison = ($right['days'] ?? -1) <=> ($left['days'] ?? -1);
                if ($comparison !== 0) {
                    return $comparison;
                }

                $comparison = strcmp($left['_stable_identifier'], $right['_stable_identifier']);

                return $comparison !== 0 ? $comparison : ($left['_local_id'] <=> $right['_local_id']);
            })
            ->take($requestedUnits)
            ->values()
            ->map(function (array $row, int $index): array {
                unset($row['_stable_identifier'], $row['_local_id']);

                return ['position' => $index + 1, ...$row];
            });

        $proposedUnits = $rows->count();
        $currentStock = (int) data_get($context, 'stock.'.$destinationId.'.total', 0);
        $configuredCapacity = $destination->capacity_total !== null && (int) $destination->capacity_total > 0
            ? (int) $destination->capacity_total
            : null;
        $projectedStock = $currentStock + $proposedUnits;

        return [
            'origin' => $origin,
            'destination' => $destination,
            'requested_units' => $requestedUnits,
            'proposed_units' => $proposedUnits,
            'missing_units' => max($requestedUnits - $proposedUnits, 0),
            'rows' => $rows,
            'capacity' => [
                'current_stock' => $currentStock,
                'configured' => $configuredCapacity,
                'current_free_places' => $configuredCapacity !== null ? max($configuredCapacity - $currentStock, 0) : null,
                'current_excess' => $configuredCapacity !== null ? max($currentStock - $configuredCapacity, 0) : null,
                'projected_stock' => $projectedStock,
                'projected_occupancy' => $configuredCapacity !== null
                    ? round(($projectedStock / $configuredCapacity) * 100, 1)
                    : null,
                'projected_excess' => $configuredCapacity !== null ? max($projectedStock - $configuredCapacity, 0) : null,
            ],
        ];
    }
}
