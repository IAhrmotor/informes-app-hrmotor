<?php

namespace Tests\Unit;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceSaleSnapshot;
use App\Services\Reports\Stock\StockDashboardDatasetService;
use App\Services\Reports\Stock\StockSaleValidityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockSaleValidityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_excluye_cerrada_perdida_y_conserva_el_snapshot_economico(): void
    {
        $valid = $this->opportunity('006-valid', 'Contrato', '01t-shared');
        $lost = $this->opportunity('006-lost', 'Cerrada perdida', '01t-shared');
        $validSnapshot = $this->snapshot($valid, 18000);
        $lostSnapshot = $this->snapshot($lost, 22000);

        $result = app(StockSaleValidityService::class)->reconcile();

        $this->assertSame(1, $result['valid']);
        $this->assertSame(1, $result['invalid']);
        $this->assertTrue($validSnapshot->fresh()->is_valid);
        $this->assertFalse($lostSnapshot->fresh()->is_valid);
        $this->assertSame(StockSaleValidityService::REASON_CLOSED_LOST, $lostSnapshot->fresh()->invalid_reason);
        $this->assertSame('22000.00', $lostSnapshot->fresh()->sale_price);
        $this->assertSame(1, app(StockDashboardDatasetService::class)->build([], 'summary')['summary']['sales']);
    }

    public function test_no_elige_arbitrariamente_entre_dos_ventas_validas_del_mismo_vehiculo(): void
    {
        $first = $this->opportunity('006-first', 'Contrato', '01t-duplicate');
        $second = $this->opportunity('006-second', 'Cerrada ganada', '01t-duplicate');
        $firstSnapshot = $this->snapshot($first, 18000);
        $secondSnapshot = $this->snapshot($second, 18500);
        $service = app(StockSaleValidityService::class);

        $result = $service->reconcile();

        $this->assertSame(0, $result['valid']);
        $this->assertSame(2, $result['duplicates']);
        $this->assertSame(
            StockSaleValidityService::REASON_DUPLICATE_VALID_VEHICLE,
            $firstSnapshot->fresh()->invalid_reason,
        );
        $this->assertSame(
            StockSaleValidityService::REASON_DUPLICATE_VALID_VEHICLE,
            $secondSnapshot->fresh()->invalid_reason,
        );
        $this->assertSame(0, app(StockDashboardDatasetService::class)->build([], 'summary')['summary']['sales']);

        $second->update(['stage_name' => 'Cerrada perdida']);
        $service->reconcile();

        $this->assertTrue($firstSnapshot->fresh()->is_valid);
        $this->assertNull($firstSnapshot->fresh()->invalid_reason);
        $this->assertFalse($secondSnapshot->fresh()->is_valid);
        $this->assertSame(
            StockSaleValidityService::REASON_CLOSED_LOST,
            $secondSnapshot->fresh()->invalid_reason,
        );
        $this->assertSame(1, app(StockDashboardDatasetService::class)->build([], 'summary')['summary']['sales']);
    }

    private function opportunity(string $id, string $stage, string $vehicleId): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create([
            'salesforce_id' => $id,
            'record_type_name' => 'Venta',
            'stage_name' => $stage,
            'cv_signed' => true,
            'cv_signed_date' => '2026-07-20',
            'vehicle_interest_id' => $vehicleId,
        ]);
    }

    private function snapshot(SalesforceOpportunity $opportunity, int $price): SalesforceSaleSnapshot
    {
        return SalesforceSaleSnapshot::query()->create([
            'opportunity_salesforce_id' => $opportunity->salesforce_id,
            'record_type' => 'Venta',
            'signed_date' => '2026-07-20',
            'vehicle_salesforce_id' => $opportunity->vehicle_interest_id,
            'sale_price' => $price,
            'is_valid' => true,
            'captured_at' => now(),
        ]);
    }
}
