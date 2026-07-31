<?php

namespace App\Http\Controllers\Reports\Stock;

use App\Http\Controllers\Controller;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceSaleSnapshot;
use App\Models\SalesforceVehicle;
use App\Models\StockDailySnapshot;
use App\Models\StockDelegation;
use App\Services\Reports\Stock\StockDashboardDatasetService;
use App\Services\Reports\Stock\StockCatalogNormalizer;
use App\Services\Reports\Stock\StockDelegationNormalizer;
use App\Support\ReportUserAccess;
use App\Support\SimpleXlsxWorkbookWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StockDashboardController extends Controller
{
    public function index(
        Request $request,
        StockDelegationNormalizer $normalizer,
        StockDashboardDatasetService $datasetService,
        StockCatalogNormalizer $catalogNormalizer,
    ): View
    {
        $capacityDelegations = StockDelegation::query()
            ->get()
            ->map(function (StockDelegation $delegation) use ($normalizer): StockDelegation {
                $delegation->setAttribute('is_commercial', $normalizer->isCommercial($delegation->canonical_name));

                return $delegation;
            })
            ->sortBy(fn (StockDelegation $delegation): string => sprintf(
                '%d-%s',
                $delegation->is_commercial ? 0 : 1,
                $delegation->normalized_key,
            ))
            ->values();

        $activeTab = $request->query('section');
        $allowedTabs = ['summary', 'delegations', 'sales', 'rankings', 'recommendations', 'vehicles'];
        if (ReportUserAccess::isAdmin($request)) {
            $allowedTabs[] = 'capacities';
        }
        $activeTab = in_array($activeTab, $allowedTabs, true) ? $activeTab : 'summary';
        $dataset = $datasetService->build($request->query(), $activeTab);
        $qualityVehicles = SalesforceVehicle::query()->where('is_in_stock', true)->get();
        $catalogDuplicates = $catalogNormalizer->duplicateGroups($qualityVehicles);
        $excludedCatalogVehicles = $catalogNormalizer->excludedVehicles($qualityVehicles);
        return view('reports.stock.index', [
            ...$dataset,
            'reportUserRole' => ReportUserAccess::role($request),
            'isAdmin' => ReportUserAccess::isAdmin($request),
            'capacityDelegations' => $capacityDelegations,
            'activeStockTab' => $activeTab,
            'latestSnapshotDate' => StockDailySnapshot::query()->max('snapshot_date'),
            'saleSnapshotsCount' => SalesforceSaleSnapshot::query()->count(),
            'quality' => [
                'stock_missing_entry_date' => SalesforceVehicle::query()->where('is_in_stock', true)->whereNull('entry_date')->count(),
                'stock_missing_delegation' => SalesforceVehicle::query()->where('is_in_stock', true)->whereNull('stock_delegation_id')->count(),
                'stock_missing_brand' => SalesforceVehicle::query()->where('is_in_stock', true)->whereNull('brand')->count(),
                'stock_missing_model' => SalesforceVehicle::query()->where('is_in_stock', true)->whereNull('model')->count(),
                'stock_missing_segment' => SalesforceVehicle::query()->where('is_in_stock', true)->whereNull('segment')->count(),
                'stock_missing_fuel' => SalesforceVehicle::query()->where('is_in_stock', true)->whereNull('fuel')->count(),
                'stock_delivered' => SalesforceVehicle::query()
                    ->where('is_in_stock', true)
                    ->whereIn('salesforce_id', SalesforceSaleSnapshot::query()->where('is_valid', true)->select('vehicle_salesforce_id'))
                    ->count(),
                'stock_commercial_without_zone' => SalesforceVehicle::query()
                    ->where('is_in_stock', true)
                    ->whereHas('delegation', fn ($query) => $query->where('is_commercial', true)->whereNull('zone'))
                    ->count(),
                'sales_missing_signed_date' => SalesforceSaleSnapshot::query()->whereNull('signed_date')->count(),
                'sales_missing_delivery_store' => SalesforceSaleSnapshot::query()->whereNull('delivery_store')->count(),
                'sales_missing_entry_date' => SalesforceSaleSnapshot::query()->whereNull('vehicle_entry_date')->count(),
                'sales_missing_price' => SalesforceSaleSnapshot::query()->whereNull('sale_price')->count(),
                'signed_date_without_contract' => SalesforceOpportunity::query()
                    ->whereNotNull('cv_signed_date')
                    ->where('cv_signed', false)
                    ->count(),
                'signed_closed_lost' => SalesforceOpportunity::query()
                    ->where('cv_signed', true)
                    ->whereRaw('LOWER(stage_name) = ?', ['cerrada perdida'])
                    ->count(),
                'duplicate_valid_vehicle' => SalesforceSaleSnapshot::query()
                    ->where('invalid_reason', 'duplicate_valid_vehicle')
                    ->whereNotNull('vehicle_salesforce_id')
                    ->distinct()
                    ->count('vehicle_salesforce_id'),
                'signed_unexpected_stage' => $this->unexpectedSignedStageQuery()->count(),
                'future_entry_date' => SalesforceVehicle::query()
                    ->where('is_in_stock', true)
                    ->whereDate('entry_date', '>', today(config('app.timezone')))
                    ->count(),
                'stores_without_capacity' => StockDelegation::query()
                    ->where('is_commercial', true)
                    ->where(fn ($query) => $query->whereNull('capacity_total')->orWhere('capacity_total', '<=', 0))
                    ->count(),
                'catalog_duplicates' => $catalogDuplicates->count(),
                'non_operational_catalog_values' => $excludedCatalogVehicles->count(),
            ],
        ]);
    }

    public function exportQualityXlsx(
        Request $request,
        SimpleXlsxWorkbookWriter $workbookWriter,
        StockCatalogNormalizer $catalogNormalizer,
    )
    {
        try {
            $vehicles = SalesforceVehicle::query()->where('is_in_stock', true);
            $sales = SalesforceSaleSnapshot::query();
            $allVehicles = $vehicles->clone()->get();
            $path = $workbookWriter->write([
                $this->vehicleQualitySheet('Stock sin entrada', $vehicles->clone()->whereNull('entry_date')->get()),
                $this->vehicleQualitySheet('Stock sin delegacion', $vehicles->clone()->whereNull('stock_delegation_id')->get()),
                $this->vehicleQualitySheet('Stock sin marca', $vehicles->clone()->whereNull('brand')->get()),
                $this->vehicleQualitySheet('Stock sin modelo', $vehicles->clone()->whereNull('model')->get()),
                $this->vehicleQualitySheet('Stock sin segmento', $vehicles->clone()->whereNull('segment')->get()),
                $this->vehicleQualitySheet('Stock sin combustible', $vehicles->clone()->whereNull('fuel')->get()),
                $this->vehicleQualitySheet('Entregados aun en stock', $vehicles->clone()
                    ->whereIn('salesforce_id', SalesforceSaleSnapshot::query()->where('is_valid', true)->select('vehicle_salesforce_id'))->get()),
                $this->vehicleQualitySheet('Tiendas sin zona', $vehicles->clone()
                    ->whereHas('delegation', fn ($query) => $query->where('is_commercial', true)->whereNull('zone'))->get()),
                $this->saleQualitySheet('Ventas sin firma', $sales->clone()->whereNull('signed_date')->get()),
                $this->saleQualitySheet('Ventas sin tienda', $sales->clone()->whereNull('delivery_store')->get()),
                $this->saleQualitySheet('Ventas sin entrada', $sales->clone()->whereNull('vehicle_entry_date')->get()),
                $this->saleQualitySheet('Ventas sin precio', $sales->clone()->whereNull('sale_price')->get()),
                $this->opportunityQualitySheet('Firma sin contrato', SalesforceOpportunity::query()
                    ->whereNotNull('cv_signed_date')->where('cv_signed', false)->get()),
                $this->opportunityQualitySheet('Firmados cerrada perdida', SalesforceOpportunity::query()
                    ->where('cv_signed', true)->whereRaw('LOWER(stage_name) = ?', ['cerrada perdida'])->get()),
                $this->saleQualitySheet('Vehiculos venta duplicada', $sales->clone()
                    ->where('invalid_reason', 'duplicate_valid_vehicle')->get()),
                $this->opportunityQualitySheet('Fases inesperadas', $this->unexpectedSignedStageQuery()->get()),
                $this->vehicleQualitySheet('Entradas futuras', $vehicles->clone()
                    ->whereDate('entry_date', '>', today(config('app.timezone')))->get()),
                $this->delegationQualitySheet('Tiendas sin capacidad', StockDelegation::query()
                    ->where('is_commercial', true)
                    ->where(fn ($query) => $query->whereNull('capacity_total')->orWhere('capacity_total', '<=', 0))
                    ->get()),
                $this->catalogDuplicateQualitySheet('Catalogos duplicados', $catalogNormalizer->duplicateGroups($allVehicles)),
                $this->vehicleQualitySheet('Valores no operativos', $catalogNormalizer->excludedVehicles($allVehicles)),
            ]);

            return response()
                ->download($path, 'calidad-dato-stock-'.now()->format('Ymd-His').'.xlsx', [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {
            Log::error('No se pudo exportar la calidad de datos de stock.', ['exception' => $exception]);

            return redirect()
                ->route('reports.stock.index', ['section' => 'summary'])
                ->withErrors(['quality_export' => 'No se pudo generar el Excel de calidad del dato.']);
        }
    }

    /** @return array{name: string, headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>} */
    private function vehicleQualitySheet(string $name, iterable $vehicles): array
    {
        return [
            'name' => $name,
            'headers' => ['ID Salesforce vehiculo', 'Matricula', 'Vehiculo', 'Estado', 'Delegacion Salesforce', 'Fecha entrada', 'Segmento', 'Combustible'],
            'rows' => collect($vehicles)->map(fn (SalesforceVehicle $vehicle): array => [
                $vehicle->salesforce_id,
                $vehicle->plate,
                $vehicle->name,
                $vehicle->state,
                $vehicle->salesforce_delegation_name,
                $vehicle->entry_date?->toDateString(),
                $vehicle->segment,
                $vehicle->fuel,
            ])->all(),
        ];
    }

    /** @return array{name: string, headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>} */
    private function saleQualitySheet(string $name, iterable $sales): array
    {
        $sales = collect($sales);
        $vehiclesBySalesforceId = SalesforceVehicle::query()
            ->whereIn('salesforce_id', $sales->pluck('vehicle_salesforce_id')->filter()->unique()->values())
            ->get(['salesforce_id', 'plate'])
            ->keyBy('salesforce_id');

        return [
            'name' => $name,
            'headers' => ['ID Salesforce oportunidad', 'ID Salesforce vehiculo', 'Matricula', 'Oportunidad', 'Tipo', 'Fecha firma', 'Tienda entrega', 'Fecha entrada', 'Precio contractual'],
            'rows' => $sales->map(fn (SalesforceSaleSnapshot $sale): array => [
                $sale->opportunity_salesforce_id,
                $sale->vehicle_salesforce_id,
                $sale->vehicle_plate ?: $vehiclesBySalesforceId->get($sale->vehicle_salesforce_id)?->plate,
                $sale->opportunity_name,
                $sale->record_type,
                $sale->signed_date?->toDateString(),
                $sale->delivery_store,
                $sale->vehicle_entry_date?->toDateString(),
                $sale->sale_price,
            ])->all(),
        ];
    }

    private function opportunityQualitySheet(string $name, iterable $opportunities): array
    {
        return [
            'name' => $name,
            'headers' => ['ID Salesforce oportunidad', 'ID Salesforce vehiculo', 'Matricula', 'Oportunidad', 'Tipo', 'Fase', 'Contrato firmado', 'Fecha firma', 'Tienda entrega'],
            'rows' => collect($opportunities)->map(fn (SalesforceOpportunity $opportunity): array => [
                $opportunity->salesforce_id,
                $opportunity->vehicle_interest_id,
                $opportunity->vehicle_plate,
                $opportunity->name,
                $opportunity->record_type_name,
                $opportunity->stage_name,
                $opportunity->cv_signed ? 'Sí' : 'No',
                $opportunity->cv_signed_date?->toDateString(),
                $opportunity->delivery_store,
            ])->all(),
        ];
    }

    private function delegationQualitySheet(string $name, iterable $delegations): array
    {
        return [
            'name' => $name,
            'headers' => ['Delegación', 'Nombre Salesforce', 'Zona', 'Grupo', 'Capacidad'],
            'rows' => collect($delegations)->map(fn (StockDelegation $delegation): array => [
                $delegation->canonical_name,
                $delegation->salesforce_name,
                $delegation->zone,
                $delegation->commercial_group,
                $delegation->capacity_total,
            ])->all(),
        ];
    }

    private function catalogDuplicateQualitySheet(string $name, iterable $groups): array
    {
        return [
            'name' => $name,
            'headers' => ['Catálogo', 'Clave normalizada', 'Valores originales', 'IDs Salesforce de vehículos'],
            'rows' => collect($groups)->map(fn (array $group): array => [
                $group['dimension'],
                $group['normalized_key'],
                implode(' | ', $group['raw_values']),
                implode(' | ', $group['vehicles']),
            ])->all(),
        ];
    }

    private function unexpectedSignedStageQuery()
    {
        $expected = collect(config('stock.expected_signed_stages', []))
            ->map(fn ($stage): string => Str::of((string) $stage)->lower()->ascii()->squish()->toString())
            ->values()
            ->all();

        return SalesforceOpportunity::query()
            ->where('cv_signed', true)
            ->where(function ($query) use ($expected): void {
                $query->whereNull('stage_name')
                    ->orWhereNotIn(\DB::raw('LOWER(stage_name)'), [...$expected, 'cerrada perdida']);
            });
    }
}
