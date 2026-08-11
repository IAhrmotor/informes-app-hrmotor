<?php

namespace Tests\Unit;

use App\Models\OperationalAlert;
use App\Models\SalesforceVehicle;
use App\Models\StockAvailabilityAlert;
use App\Models\StockDelegation;
use App\Services\Reports\Stock\StockAvailabilityAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAvailabilityAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_repite_alerta_administrativa_y_la_reabre_despues_de_recuperar_stock(): void
    {
        $delegation = StockDelegation::query()->create([
            'canonical_name' => 'Rivas',
            'normalized_key' => 'rivas',
            'capacity_total' => 10,
            'is_commercial' => true,
        ]);

        $service = app(StockAvailabilityAlertService::class);
        $this->assertSame(1, $service->evaluate()['opened']);
        $this->assertSame(0, $service->evaluate()['opened']);
        $this->assertSame(1, StockAvailabilityAlert::query()->where('state', 'open')->count());
        $this->assertSame(1, OperationalAlert::query()->count());
        $this->assertSame(OperationalAlert::STATE_OPEN, OperationalAlert::query()->value('state'));

        $vehicle = SalesforceVehicle::query()->create([
            'salesforce_id' => '01t-alert',
            'stock_delegation_id' => $delegation->id,
            'state' => 'Disponible',
            'is_in_stock' => true,
        ]);
        $this->assertSame(1, $service->evaluate()['resolved']);
        $this->assertSame(OperationalAlert::STATE_RESOLVED, OperationalAlert::query()->value('state'));

        $vehicle->update(['is_in_stock' => false]);
        $this->assertSame(1, $service->evaluate()['opened']);
        $this->assertSame(2, StockAvailabilityAlert::query()->count());
        $this->assertSame(1, OperationalAlert::query()->count());
        $this->assertSame(OperationalAlert::STATE_OPEN, OperationalAlert::query()->value('state'));
        $this->assertSame(0, StockAvailabilityAlert::query()->whereNotNull('email_sent_at')->count());
        $this->assertSame(0, StockAvailabilityAlert::query()->whereNotNull('salesforce_task_id')->count());
    }
}
