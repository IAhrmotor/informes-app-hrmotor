<?php

namespace App\Http\Controllers\Reports\Stock;

use App\Http\Controllers\Controller;
use App\Models\SalesforceSaleSnapshot;
use App\Models\SalesforceVehicle;
use App\Models\StockDailySnapshot;
use App\Models\StockDelegation;
use App\Support\ReportUserAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $vehicles = SalesforceVehicle::query()
            ->with('delegation:id,canonical_name,capacity_total,is_commercial,zone')
            ->where('is_in_stock', true)
            ->get();
        $total = $vehicles->count();
        $purchaseValue = (float) $vehicles->sum(fn (SalesforceVehicle $vehicle) => (float) ($vehicle->purchase_price ?? 0));
        $saleValue = (float) $vehicles->sum(fn (SalesforceVehicle $vehicle) => (float) ($vehicle->sale_price ?? 0));
        $ages = $vehicles
            ->filter(fn (SalesforceVehicle $vehicle) => $vehicle->entry_date !== null)
            ->map(fn (SalesforceVehicle $vehicle) => (int) CarbonImmutable::parse($vehicle->entry_date)->diffInDays($today));

        $delegations = StockDelegation::query()
            ->orderByDesc('is_commercial')
            ->orderBy('canonical_name')
            ->get()
            ->map(function (StockDelegation $delegation) use ($vehicles): array {
                $stock = $vehicles->where('stock_delegation_id', $delegation->id);
                $total = $stock->count();
                $capacity = $delegation->capacity_total;

                return [
                    'model' => $delegation,
                    'total' => $total,
                    'available' => $stock->where('state', 'Disponible')->count(),
                    'reserved' => $stock->where('state', 'Reservado')->count(),
                    'blocked' => $stock->where('state', 'Bloqueado')->count(),
                    'free_capacity' => $capacity !== null ? $capacity - $total : null,
                    'occupancy' => $capacity > 0 ? round(($total / $capacity) * 100, 1) : null,
                ];
            })
            ->filter(fn (array $row) => $row['total'] > 0 || $row['model']->capacity_total !== null)
            ->values();

        return view('reports.stock.index', [
            'reportUserRole' => ReportUserAccess::role($request),
            'isAdmin' => ReportUserAccess::isAdmin($request),
            'summary' => [
                'total' => $total,
                'available' => $vehicles->where('state', 'Disponible')->count(),
                'reserved' => $vehicles->where('state', 'Reservado')->count(),
                'blocked' => $vehicles->where('state', 'Bloqueado')->count(),
                'purchase_value' => $purchaseValue,
                'sale_value' => $saleValue,
                'potential_margin' => $saleValue - $purchaseValue,
                'average_purchase_price' => $total > 0 ? $purchaseValue / $total : 0,
                'average_sale_price' => $total > 0 ? $saleValue / $total : 0,
                'average_age' => $ages->isNotEmpty() ? round($ages->average(), 1) : null,
                'over_60' => $ages->filter(fn (int $days) => $days >= 60)->count(),
                'over_90' => $ages->filter(fn (int $days) => $days >= 90)->count(),
                'over_120' => $ages->filter(fn (int $days) => $days >= 120)->count(),
                'over_180' => $ages->filter(fn (int $days) => $days >= 180)->count(),
            ],
            'delegations' => $delegations,
            'capacityDelegations' => StockDelegation::query()
                ->orderByDesc('is_commercial')
                ->orderBy('canonical_name')
                ->get(),
            'latestSnapshotDate' => StockDailySnapshot::query()->max('snapshot_date'),
            'saleSnapshotsCount' => SalesforceSaleSnapshot::query()->count(),
            'quality' => [
                'stock_missing_entry_date' => $vehicles->whereNull('entry_date')->count(),
                'stock_missing_delegation' => $vehicles->whereNull('stock_delegation_id')->count(),
                'stock_missing_segment' => $vehicles->whereNull('segment')->count(),
                'stock_missing_fuel' => $vehicles->whereNull('fuel')->count(),
                'sales_missing_signed_date' => SalesforceSaleSnapshot::query()->whereNull('signed_date')->count(),
                'sales_missing_delivery_store' => SalesforceSaleSnapshot::query()->whereNull('delivery_store')->count(),
                'sales_missing_entry_date' => SalesforceSaleSnapshot::query()->whereNull('vehicle_entry_date')->count(),
                'sales_missing_price' => SalesforceSaleSnapshot::query()->whereNull('sale_price')->count(),
            ],
        ]);
    }
}
