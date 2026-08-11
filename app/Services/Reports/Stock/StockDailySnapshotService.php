<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceVehicle;
use App\Models\StockDailySnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockDailySnapshotService
{
    public function capture(CarbonImmutable $date): int
    {
        $captured = 0;

        DB::transaction(function () use ($date, &$captured): void {
            SalesforceVehicle::query()
                ->with('delegation:id,canonical_name')
                ->where('is_in_stock', true)
                ->orderBy('id')
                ->chunkById(500, function (Collection $vehicles) use ($date, &$captured): void {
                    $now = now();
                    $rows = $vehicles->map(function (SalesforceVehicle $vehicle) use ($date, $now): array {
                        $entryDate = $vehicle->entry_date
                            ? CarbonImmutable::parse($vehicle->entry_date)->startOfDay()
                            : null;

                        return [
                            'snapshot_date' => $date->toDateString(),
                            'salesforce_vehicle_id' => $vehicle->id,
                            'vehicle_salesforce_id' => $vehicle->salesforce_id,
                            'state' => $vehicle->state,
                            'stock_delegation_id' => $vehicle->stock_delegation_id,
                            'delegation_name' => $vehicle->delegation?->canonical_name,
                            'brand' => $vehicle->brand,
                            'model' => $vehicle->model,
                            'segment' => $vehicle->segment,
                            'fuel' => $vehicle->fuel,
                            'price_band' => $this->priceBand($vehicle->sale_price),
                            'purchase_price' => $vehicle->purchase_price,
                            'sale_price' => $vehicle->sale_price,
                            'entry_date' => $entryDate?->toDateString(),
                            'days_in_stock' => $entryDate && $entryDate->lessThanOrEqualTo($date)
                                ? $entryDate->diffInDays($date)
                                : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    })->all();

                    StockDailySnapshot::query()->upsert(
                        $rows,
                        ['snapshot_date', 'salesforce_vehicle_id'],
                        [
                            'vehicle_salesforce_id', 'state', 'stock_delegation_id', 'delegation_name',
                            'brand', 'model', 'segment', 'fuel', 'price_band', 'purchase_price',
                            'sale_price', 'entry_date', 'days_in_stock', 'updated_at',
                        ],
                    );
                    $captured += count($rows);
                });

            StockDailySnapshot::query()
                ->whereDate('snapshot_date', $date->toDateString())
                ->whereNotIn('salesforce_vehicle_id', SalesforceVehicle::query()
                    ->where('is_in_stock', true)
                    ->select('id'))
                ->delete();
        });

        return $captured;
    }

    public function priceBand(mixed $price): ?string
    {
        if (! is_numeric($price) || (float) $price < 0) {
            return null;
        }

        $lower = (int) floor((float) $price / 5000) * 5000;
        $upper = $lower + 5000;

        return number_format($lower, 0, ',', '.').'–'.number_format($upper, 0, ',', '.').' €';
    }
}
