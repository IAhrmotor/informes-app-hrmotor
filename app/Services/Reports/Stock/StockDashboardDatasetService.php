<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceSaleSnapshot;
use App\Models\SalesforceVehicle;
use App\Models\StockDailySnapshot;
use App\Models\StockDelegation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StockDashboardDatasetService
{
    public function __construct(
        private readonly StockRecommendationService $recommendations,
        private readonly StockDelegationNormalizer $delegationNormalizer,
    ) {}

    public function build(array $input, string $section = 'summary'): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $filters = $this->filters($input, $today);
        $allStock = SalesforceVehicle::query()
            ->select([
                'id', 'salesforce_id', 'name', 'plate', 'brand', 'model', 'version', 'segment', 'fuel', 'body',
                'mileage', 'state', 'stock_delegation_id', 'purchase_price', 'sale_price', 'entry_date',
                'buyer_name', 'purchase_source', 'is_in_stock',
            ])
            ->with('delegation')
            ->where('is_in_stock', true)
            ->get();
        $allSales = SalesforceSaleSnapshot::query()
            ->select([
                'id', 'opportunity_salesforce_id', 'opportunity_name', 'record_type', 'signed_date',
                'delivery_store', 'stock_delegation_id', 'vehicle_salesforce_id', 'vehicle_plate',
                'vehicle_entry_date', 'rotation_days', 'sale_price', 'purchase_price',
                'vehicle_brand', 'vehicle_model', 'vehicle_segment', 'vehicle_fuel', 'vehicle_body',
                'vehicle_mileage', 'vehicle_purchase_source', 'vehicle_buyer_name',
                'trade_in_vehicle_salesforce_id', 'trade_in_vehicle_plate', 'trade_in_amount',
                'management_cost', 'logistics_cost', 'transfer_cost', 'warranty_amount',
                'plan_auto_plus_amount', 'cae_amount', 'discount_amount', 'total_amount',
            ])
            ->with('delegation')
            ->whereBetween('signed_date', [$filters['date_from'], $filters['date_to']])
            ->get();
        $delegations = StockDelegation::query()->orderBy('canonical_name')->get()
            ->each(fn (StockDelegation $delegation) => $delegation->setAttribute(
                'is_commercial',
                $this->delegationNormalizer->isCommercial($delegation->canonical_name),
            ));
        $stock = $this->filterStock($allStock, $filters, $today);
        $sales = $this->filterSales($allSales, $filters);
        $ages = $stock->map(fn (SalesforceVehicle $vehicle) => $this->age($vehicle, $today))->filter(fn ($age) => $age !== null);
        $rotations = $sales->pluck('rotation_days')->filter(fn ($days) => $days !== null);
        $purchaseValue = (float) $stock->sum(fn ($vehicle) => (float) ($vehicle->purchase_price ?? 0));
        $saleValue = (float) $stock->sum(fn ($vehicle) => (float) ($vehicle->sale_price ?? 0));
        $stockHistory = $this->stockHistory($filters);
        $currentAvailable = $stock->where('state', 'Disponible')->count();
        $stockDenominator = $stockHistory['sufficient']
            ? $stockHistory['average_available']
            : $currentAvailable;
        $salesStockRatio = $stockDenominator > 0 ? round($sales->count() / $stockDenominator, 2) : null;

        $detailRows = collect();
        $recommendationRows = collect();
        $newVehicleRecommendations = null;
        if (in_array($section, ['recommendations', 'vehicles'], true)) {
            $recommendationSales = SalesforceSaleSnapshot::query()
                ->select([
                    'id', 'signed_date', 'stock_delegation_id', 'rotation_days', 'sale_price',
                    'vehicle_brand', 'vehicle_model', 'vehicle_segment', 'vehicle_fuel',
                ])
                ->where('signed_date', '>=', $today->subDays(120)->toDateString())
                ->get();
            $recommendationContext = $this->recommendations->prepare($allStock, $recommendationSales, $delegations);
            $sameModelCounts = $allStock
                ->groupBy(fn (SalesforceVehicle $vehicle) => $vehicle->stock_delegation_id.'|'.$this->recommendations->key($vehicle->model))
                ->map->count();
            $detailRows = $stock
                ->sortByDesc(fn (SalesforceVehicle $vehicle) => $this->age($vehicle, $today) ?? -1)
                ->take(250)
                ->map(fn (SalesforceVehicle $vehicle) => $this->vehicleRow($vehicle, $recommendationContext, $sameModelCounts, $today))
                ->values();
            $recommendationRows = $detailRows
                ->where('state', 'Disponible')
                ->filter(fn (array $row) => $row['review_level'] !== 'normal' || $row['recommendations'] !== [])
                ->sortByDesc(fn (array $row) => match ($row['review_level']) {
                    'priority' => 2,
                    'review' => 1,
                    default => 0,
                })
                ->take(150)
                ->values();
            $newVehicleRecommendations = $this->newVehicleRecommendations($input, $recommendationContext);
        }

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($allStock, $delegations),
            'summary' => [
                'total' => $stock->count(),
                'available' => $currentAvailable,
                'reserved' => $stock->where('state', 'Reservado')->count(),
                'blocked' => $stock->where('state', 'Bloqueado')->count(),
                'purchase_value' => $purchaseValue,
                'sale_value' => $saleValue,
                'potential_margin' => $saleValue - $purchaseValue,
                'average_purchase_price' => $stock->isNotEmpty() ? $purchaseValue / $stock->count() : 0,
                'average_sale_price' => $stock->isNotEmpty() ? $saleValue / $stock->count() : 0,
                'average_age' => $ages->isNotEmpty() ? round($ages->average(), 1) : null,
                'average_rotation' => $rotations->isNotEmpty() ? round($rotations->average(), 1) : null,
                'sales' => $sales->count(),
                'sales_stock_ratio' => $salesStockRatio,
                'sales_stock_approximate' => ! $stockHistory['sufficient'],
                'over_60' => $ages->filter(fn (int $days) => $days >= 60)->count(),
                'over_90' => $ages->filter(fn (int $days) => $days >= 90)->count(),
                'over_120' => $ages->filter(fn (int $days) => $days >= 120)->count(),
                'over_180' => $ages->filter(fn (int $days) => $days >= 180)->count(),
            ],
            'stockHistory' => $stockHistory,
            'delegationRows' => in_array($section, ['summary', 'delegations'], true) ? $this->delegationRows($stock, $sales, $delegations, $today) : collect(),
            'salesRows' => $section === 'sales' ? $this->salesRows($sales) : collect(),
            'salesTotal' => $sales->count(),
            'distributions' => in_array($section, ['summary', 'delegations'], true) ? $this->distributions($stock) : [],
            'rankings' => $section === 'rankings' ? $this->rankings($stock, $sales, $today) : [],
            'detailRows' => $detailRows,
            'detailTotal' => $stock->count(),
            'recommendationRows' => $recommendationRows,
            'recommendationTotal' => $stock->where('state', 'Disponible')->count(),
            'newVehicleRecommendations' => $newVehicleRecommendations,
        ];
    }

    private function filters(array $input, CarbonImmutable $today): array
    {
        $from = filled($input['date_from'] ?? null)
            ? CarbonImmutable::parse($input['date_from'])->startOfDay()
            : $today->subDays(119);
        $to = filled($input['date_to'] ?? null)
            ? CarbonImmutable::parse($input['date_to'])->startOfDay()
            : $today;
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'delegation' => $this->string($input['delegation'] ?? null),
            'zone' => $this->string($input['zone'] ?? null),
            'brand' => $this->string($input['brand'] ?? null),
            'model' => $this->string($input['model'] ?? null),
            'segment' => $this->string($input['segment'] ?? null),
            'body' => $this->string($input['body'] ?? null),
            'fuel' => $this->string($input['fuel'] ?? null),
            'price_band' => $this->string($input['price_band'] ?? null),
            'price_min' => $this->number($input['price_min'] ?? null),
            'price_max' => $this->number($input['price_max'] ?? null),
            'mileage_min' => $this->number($input['mileage_min'] ?? null),
            'mileage_max' => $this->number($input['mileage_max'] ?? null),
            'days_min' => $this->number($input['days_min'] ?? null),
            'days_max' => $this->number($input['days_max'] ?? null),
            'state' => $this->string($input['state'] ?? null),
            'buyer' => $this->string($input['buyer'] ?? null),
            'purchase_source' => $this->string($input['purchase_source'] ?? null),
        ];
    }

    private function filterStock(Collection $stock, array $filters, CarbonImmutable $today): Collection
    {
        return $stock->filter(function (SalesforceVehicle $vehicle) use ($filters, $today): bool {
            return $this->matches($vehicle->delegation?->canonical_name, $filters['delegation'])
                && $this->matches($vehicle->delegation?->zone, $filters['zone'])
                && $this->matches($vehicle->brand, $filters['brand'])
                && $this->matches($vehicle->model, $filters['model'])
                && $this->matches($vehicle->segment, $filters['segment'])
                && $this->matches($vehicle->body, $filters['body'])
                && $this->matches($vehicle->fuel, $filters['fuel'])
                && $this->matches($this->recommendations->priceBand($vehicle->sale_price), $filters['price_band'])
                && $this->range($vehicle->sale_price, $filters['price_min'], $filters['price_max'])
                && $this->range($vehicle->mileage, $filters['mileage_min'], $filters['mileage_max'])
                && $this->range($this->age($vehicle, $today), $filters['days_min'], $filters['days_max'])
                && $this->matches($vehicle->state, $filters['state'])
                && $this->matches($vehicle->buyer_name, $filters['buyer'])
                && $this->matches($vehicle->purchase_source, $filters['purchase_source']);
        })->values();
    }

    private function filterSales(Collection $sales, array $filters): Collection
    {
        return $sales->filter(function (SalesforceSaleSnapshot $sale) use ($filters): bool {
            return $this->matches($sale->delegation?->canonical_name, $filters['delegation'])
                && $this->matches($sale->delegation?->zone, $filters['zone'])
                && $this->matches($sale->vehicle_brand, $filters['brand'])
                && $this->matches($sale->vehicle_model, $filters['model'])
                && $this->matches($sale->vehicle_segment, $filters['segment'])
                && $this->matches($sale->vehicle_body, $filters['body'])
                && $this->matches($sale->vehicle_fuel, $filters['fuel'])
                && $this->matches($this->recommendations->priceBand($sale->sale_price), $filters['price_band'])
                && $this->range($sale->sale_price, $filters['price_min'], $filters['price_max'])
                && $this->range($sale->vehicle_mileage, $filters['mileage_min'], $filters['mileage_max'])
                && $this->range($sale->rotation_days, $filters['days_min'], $filters['days_max'])
                && $this->matches($sale->vehicle_buyer_name, $filters['buyer'])
                && $this->matches($sale->vehicle_purchase_source, $filters['purchase_source']);
        })->values();
    }

    private function delegationRows(Collection $stock, Collection $sales, Collection $delegations, CarbonImmutable $today): Collection
    {
        return $delegations->map(function (StockDelegation $delegation) use ($stock, $sales, $today): array {
            $delegationStock = $stock->where('stock_delegation_id', $delegation->id);
            $delegationSales = $sales->where('stock_delegation_id', $delegation->id);
            $ages = $delegationStock->map(fn ($vehicle) => $this->age($vehicle, $today))->filter(fn ($age) => $age !== null);
            $rotations = $delegationSales->pluck('rotation_days')->filter(fn ($days) => $days !== null);
            $purchase = (float) $delegationStock->sum(fn ($vehicle) => (float) ($vehicle->purchase_price ?? 0));
            $sale = (float) $delegationStock->sum(fn ($vehicle) => (float) ($vehicle->sale_price ?? 0));
            $total = $delegationStock->count();
            $capacity = $delegation->capacity_total;

            return [
                'model' => $delegation,
                'total' => $total,
                'available' => $delegationStock->where('state', 'Disponible')->count(),
                'reserved' => $delegationStock->where('state', 'Reservado')->count(),
                'blocked' => $delegationStock->where('state', 'Bloqueado')->count(),
                'free_capacity' => $capacity !== null ? $capacity - $total : null,
                'occupancy' => $capacity > 0 ? round(($total / $capacity) * 100, 1) : null,
                'purchase_value' => $purchase,
                'sale_value' => $sale,
                'average_price' => $total > 0 ? $sale / $total : null,
                'average_age' => $ages->isNotEmpty() ? round($ages->average(), 1) : null,
                'average_rotation' => $rotations->isNotEmpty() ? round($rotations->average(), 1) : null,
                'sales' => $delegationSales->count(),
                'sales_per_stock' => $total > 0 ? round($delegationSales->count() / $total, 2) : null,
                'over_60' => $ages->filter(fn ($days) => $days >= 60)->count(),
                'over_90' => $ages->filter(fn ($days) => $days >= 90)->count(),
                'over_120' => $ages->filter(fn ($days) => $days >= 120)->count(),
                'over_180' => $ages->filter(fn ($days) => $days >= 180)->count(),
                'is_commercial' => $delegation->is_commercial,
            ];
        })->filter(fn (array $row) => $row['is_commercial'] || $row['total'] > 0 || $row['model']->capacity_total !== null)
            ->sortBy(fn (array $row) => sprintf('%d-%s', $row['is_commercial'] ? 0 : 1, $row['model']->normalized_key))
            ->values();
    }

    private function distributions(Collection $stock): array
    {
        return [
            'brand' => $this->distribution($stock, fn ($vehicle) => $vehicle->brand),
            'model' => $this->distribution($stock, fn ($vehicle) => $vehicle->model),
            'segment' => $this->distribution($stock, fn ($vehicle) => $vehicle->segment),
            'fuel' => $this->distribution($stock, fn ($vehicle) => $vehicle->fuel),
            'body' => $this->distribution($stock, fn ($vehicle) => $vehicle->body),
            'price_band' => $this->distribution($stock, fn ($vehicle) => $this->recommendations->priceBand($vehicle->sale_price)),
        ];
    }

    private function salesRows(Collection $sales): Collection
    {
        return $sales->sortByDesc('signed_date')->take(250)->map(fn (SalesforceSaleSnapshot $sale): array => [
            'opportunity_id' => $sale->opportunity_salesforce_id,
            'opportunity' => $sale->opportunity_name,
            'type' => $sale->record_type,
            'signed_date' => $sale->signed_date?->toDateString(),
            'delivery_store' => $sale->delegation?->canonical_name ?: $sale->delivery_store,
            'vehicle_id' => $sale->vehicle_salesforce_id,
            'plate' => $sale->vehicle_plate,
            'vehicle' => trim(($sale->vehicle_brand ?? '').' '.($sale->vehicle_model ?? '')),
            'sale_price' => $sale->sale_price !== null ? (float) $sale->sale_price : null,
            'purchase_price' => $sale->purchase_price !== null ? (float) $sale->purchase_price : null,
            'gross_margin' => $sale->sale_price !== null && $sale->purchase_price !== null
                ? (float) $sale->sale_price - (float) $sale->purchase_price
                : null,
            'rotation_days' => $sale->rotation_days,
            'trade_in_id' => $sale->trade_in_vehicle_salesforce_id,
            'trade_in_plate' => $sale->trade_in_vehicle_plate,
            'trade_in_amount' => $sale->trade_in_amount !== null ? (float) $sale->trade_in_amount : null,
            'management' => $sale->management_cost !== null ? (float) $sale->management_cost : null,
            'logistics' => $sale->logistics_cost !== null ? (float) $sale->logistics_cost : null,
            'transfer' => $sale->transfer_cost !== null ? (float) $sale->transfer_cost : null,
            'warranty' => $sale->warranty_amount !== null ? (float) $sale->warranty_amount : null,
            'plan_auto_plus' => $sale->plan_auto_plus_amount !== null ? (float) $sale->plan_auto_plus_amount : null,
            'cae' => $sale->cae_amount !== null ? (float) $sale->cae_amount : null,
            'discount' => $sale->discount_amount !== null ? (float) $sale->discount_amount : null,
            'total_amount' => $sale->total_amount !== null ? (float) $sale->total_amount : null,
        ])->values();
    }

    private function distribution(Collection $items, callable $resolver): array
    {
        return $items->groupBy(fn ($item) => filled($resolver($item)) ? (string) $resolver($item) : 'Sin dato')
            ->map(fn (Collection $rows, string $label) => ['label' => $label, 'value' => $rows->count()])
            ->sortByDesc('value')
            ->take(10)
            ->values()
            ->all();
    }

    private function rankings(Collection $stock, Collection $sales, CarbonImmutable $today): array
    {
        $dimensions = [
            'brand' => ['Marcas', fn ($item, $isSale) => $isSale ? $item->vehicle_brand : $item->brand],
            'model' => ['Modelos', fn ($item, $isSale) => $isSale ? $item->vehicle_model : $item->model],
            'segment' => ['Segmentos', fn ($item, $isSale) => $isSale ? $item->vehicle_segment : $item->segment],
            'fuel' => ['Combustibles', fn ($item, $isSale) => $isSale ? $item->vehicle_fuel : $item->fuel],
            'price_band' => ['Tramos de precio', fn ($item) => $this->recommendations->priceBand($item->sale_price)],
            'body' => ['Carrocerías', fn ($item, $isSale) => $isSale ? $item->vehicle_body : $item->body],
            'purchase_source' => ['Procedencias', fn ($item, $isSale) => $isSale ? $item->vehicle_purchase_source : $item->purchase_source],
            'mileage' => ['Kilometraje', fn ($item, $isSale) => $this->recommendations->mileageBand($isSale ? $item->vehicle_mileage : $item->mileage)],
            'age' => ['Antigüedad / rotación', fn ($item, $isSale) => $this->recommendations->ageBand($isSale ? $item->rotation_days : $this->age($item, $today))],
        ];
        $result = [];
        foreach ($dimensions as $key => [$label, $resolver]) {
            $saleGroups = $sales->groupBy(fn ($item) => filled($resolver($item, true)) ? (string) $resolver($item, true) : 'Sin dato');
            $stockGroups = $stock->groupBy(fn ($item) => filled($resolver($item, false)) ? (string) $resolver($item, false) : 'Sin dato');
            $rows = collect($saleGroups->keys())->merge($stockGroups->keys())->unique()->map(function (string $value) use ($saleGroups, $stockGroups): array {
                $sold = $saleGroups->get($value, collect());
                $current = $stockGroups->get($value, collect());
                $rotations = $sold->pluck('rotation_days')->filter(fn ($days) => $days !== null);
                $ages = $current->map(fn ($vehicle) => $this->age($vehicle, CarbonImmutable::today(config('app.timezone'))))->filter(fn ($age) => $age !== null);
                $salesCount = $sold->count();
                $stockCount = $current->count();
                $performance = $stockCount > 0 ? $salesCount / $stockCount : ($salesCount > 0 ? $salesCount : 0);

                return [
                    'label' => $value,
                    'sales' => $salesCount,
                    'stock' => $stockCount,
                    'rotation' => $rotations->isNotEmpty() ? round($rotations->average(), 1) : null,
                    'age' => $ages->isNotEmpty() ? round($ages->average(), 1) : null,
                    'performance' => round($performance, 2),
                ];
            })->sort(function (array $left, array $right): int {
                return [$right['performance'], $right['sales']] <=> [$left['performance'], $left['sales']];
            })->take((int) config('stock.ranking_limit', 10))->values();
            $result[$key] = ['label' => $label, 'rows' => $rows];
        }

        return $result;
    }

    private function stockHistory(array $filters): array
    {
        $query = StockDailySnapshot::query()
            ->whereBetween('snapshot_date', [$filters['date_from'], $filters['date_to']]);
        $this->applySnapshotFilters($query, $filters);
        $historyRows = (clone $query)
            ->selectRaw('snapshot_date, state, COUNT(*) as total')
            ->groupBy('snapshot_date', 'state')
            ->orderBy('snapshot_date')
            ->get();
        $dates = $historyRows->pluck('snapshot_date')->map(fn ($date) => $date->toDateString())->unique();
        $expected = CarbonImmutable::parse($filters['date_from'])->diffInDays(CarbonImmutable::parse($filters['date_to'])) + 1;
        $coverage = $expected > 0 ? $dates->count() / $expected : 0;
        $availableByDate = $historyRows->where('state', 'Disponible')->groupBy(fn ($row) => $row->snapshot_date->toDateString())
            ->map(fn ($rows) => (int) $rows->sum('total'));

        return [
            'days' => $dates->count(),
            'expected_days' => $expected,
            'coverage' => round($coverage * 100, 1),
            'sufficient' => $coverage >= 0.8,
            'average_available' => $availableByDate->isNotEmpty() ? round($availableByDate->average(), 1) : 0,
            'series' => $dates->map(function (string $date) use ($historyRows): array {
                $rows = $historyRows->filter(fn ($row) => $row->snapshot_date->toDateString() === $date);

                return [
                    'date' => $date,
                    'available' => (int) $rows->where('state', 'Disponible')->sum('total'),
                    'reserved' => (int) $rows->where('state', 'Reservado')->sum('total'),
                    'blocked' => (int) $rows->where('state', 'Bloqueado')->sum('total'),
                    'total' => (int) $rows->sum('total'),
                ];
            })->values(),
        ];
    }

    private function applySnapshotFilters(Builder $query, array $filters): void
    {
        foreach (['brand', 'model', 'segment', 'fuel', 'price_band'] as $field) {
            if ($filters[$field] !== null) {
                $column = $field === 'price_band' ? 'price_band' : $field;
                $query->where($column, $filters[$field]);
            }
        }
        if ($filters['delegation'] !== null) {
            $query->where('delegation_name', $filters['delegation']);
        }
    }

    private function vehicleRow(SalesforceVehicle $vehicle, array $context, Collection $sameModelCounts, CarbonImmutable $today): array
    {
        $age = $this->age($vehicle, $today);
        $sameModel = (int) $sameModelCounts->get(
            $vehicle->stock_delegation_id.'|'.$this->recommendations->key($vehicle->model),
            0,
        );
        $reviewLevel = $age !== null && $age >= (int) config('stock.priority_days', 90)
            ? 'priority'
            : (($age !== null && $age >= (int) config('stock.review_days', 60)) ? 'review' : 'normal');
        if ($sameModel >= (int) config('stock.duplicate_model_priority', 3)) {
            $reviewLevel = 'priority';
        }
        $recommendations = $vehicle->state === 'Disponible'
            ? $this->recommendations->recommend($vehicle, $context)
            : [];
        $currentProfile = $this->recommendations->currentProfile($vehicle, $context);
        if (
            $vehicle->state === 'Disponible'
            && $currentProfile
            && isset($recommendations[0])
            && $recommendations[0]['score'] >= $currentProfile['score'] + (float) config('stock.clearly_better_score_delta', 40)
        ) {
            $reviewLevel = 'priority';
        }

        return [
            'id' => $vehicle->salesforce_id,
            'plate' => $vehicle->plate,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'version' => $vehicle->version,
            'delegation' => $vehicle->delegation?->canonical_name,
            'state' => $vehicle->state,
            'entry_date' => $vehicle->entry_date?->toDateString(),
            'days' => $age,
            'purchase_price' => $vehicle->purchase_price !== null ? (float) $vehicle->purchase_price : null,
            'sale_price' => $vehicle->sale_price !== null ? (float) $vehicle->sale_price : null,
            'margin' => $vehicle->sale_price !== null && $vehicle->purchase_price !== null
                ? (float) $vehicle->sale_price - (float) $vehicle->purchase_price
                : null,
            'segment' => $vehicle->segment,
            'fuel' => $vehicle->fuel,
            'body' => $vehicle->body,
            'mileage' => $vehicle->mileage,
            'buyer' => $vehicle->buyer_name,
            'purchase_source' => $vehicle->purchase_source,
            'price_band' => $this->recommendations->priceBand($vehicle->sale_price),
            'same_model_stock' => $sameModel,
            'review_level' => $reviewLevel,
            'recommendations' => $recommendations,
            'current_profile' => $currentProfile,
        ];
    }

    private function newVehicleRecommendations(array $input, array $context): ?array
    {
        if (! filled($input['rec_model'] ?? null) && ! filled($input['rec_brand'] ?? null)) {
            return null;
        }
        $vehicle = new SalesforceVehicle([
            'brand' => $this->string($input['rec_brand'] ?? null),
            'model' => $this->string($input['rec_model'] ?? null),
            'segment' => $this->string($input['rec_segment'] ?? null),
            'fuel' => $this->string($input['rec_fuel'] ?? null),
            'sale_price' => $this->number($input['rec_price'] ?? null),
        ]);

        return [
            'vehicle' => $vehicle,
            'rows' => $this->recommendations->recommend($vehicle, $context, false),
        ];
    }

    private function filterOptions(Collection $stock, Collection $delegations): array
    {
        $option = fn (string $field) => $stock->pluck($field)->filter()->unique()->sort()->values();

        return [
            'delegations' => $delegations->where('is_commercial', true)->pluck('canonical_name')->filter()->sort()->values(),
            'zones' => $delegations->pluck('zone')->filter()->unique()->sort()->values(),
            'brands' => $option('brand'),
            'models' => $option('model'),
            'segments' => $option('segment'),
            'bodies' => $option('body'),
            'fuels' => $option('fuel'),
            'price_bands' => $stock->map(fn ($vehicle) => $this->recommendations->priceBand($vehicle->sale_price))
                ->unique()
                ->sortBy(function (string $band): int {
                    if ($band === 'Sin precio') {
                        return PHP_INT_MAX;
                    }

                    return (int) preg_replace('/\D/', '', explode('–', $band)[0]);
                })
                ->values(),
            'states' => collect(SalesforceVehicle::STOCK_STATES),
            'buyers' => $option('buyer_name'),
            'purchase_sources' => $option('purchase_source'),
        ];
    }

    private function age(SalesforceVehicle $vehicle, CarbonImmutable $today): ?int
    {
        return $vehicle->entry_date ? (int) CarbonImmutable::parse($vehicle->entry_date)->diffInDays($today) : null;
    }

    private function matches(mixed $actual, ?string $expected): bool
    {
        return $expected === null || $this->recommendations->key($actual) === $this->recommendations->key($expected);
    }

    private function range(mixed $actual, ?float $minimum, ?float $maximum): bool
    {
        if ($minimum === null && $maximum === null) {
            return true;
        }
        if (! is_numeric($actual)) {
            return false;
        }
        $value = (float) $actual;

        return ($minimum === null || $value >= $minimum) && ($maximum === null || $value <= $maximum);
    }

    private function string(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
