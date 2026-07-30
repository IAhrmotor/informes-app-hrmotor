<?php

namespace Tests\Unit;

use App\Models\SalesforceVehicle;
use App\Models\StockAvailabilityAlert;
use App\Models\StockDelegation;
use App\Services\Reports\Stock\StockAvailabilityAlertService;
use App\Services\Salesforce\SalesforceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StockAvailabilityAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_repite_alerta_y_la_reabre_despues_de_recuperar_stock(): void
    {
        Mail::fake();
        $client = new class extends SalesforceClient
        {
            public array $created = [];
            public array $updated = [];
            public function __construct() {}
            public function create(string $object, array $fields): string
            {
                $this->created[] = compact('object', 'fields');

                return '00T'.count($this->created);
            }
            public function update(string $object, string $id, array $fields): void
            {
                $this->updated[] = compact('object', 'id', 'fields');
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        $delegation = StockDelegation::query()->create([
            'canonical_name' => 'Rivas',
            'normalized_key' => 'rivas',
            'capacity_total' => 10,
            'is_commercial' => true,
        ]);

        $service = app(StockAvailabilityAlertService::class);
        $this->assertSame(1, $service->evaluate()['opened']);
        $this->assertSame(0, $service->evaluate()['opened']);
        $this->assertCount(1, $client->created);
        $this->assertSame(1, StockAvailabilityAlert::query()->where('state', 'open')->count());

        $vehicle = SalesforceVehicle::query()->create([
            'salesforce_id' => '01t-alert',
            'stock_delegation_id' => $delegation->id,
            'state' => 'Disponible',
            'is_in_stock' => true,
        ]);
        $this->assertSame(1, $service->evaluate()['resolved']);
        $this->assertSame('Completed', $client->updated[0]['fields']['Status']);

        $vehicle->update(['is_in_stock' => false]);
        $this->assertSame(1, $service->evaluate()['opened']);
        $this->assertCount(2, $client->created);
        $this->assertSame(2, StockAvailabilityAlert::query()->count());
        $this->assertSame(2, StockAvailabilityAlert::query()->whereNotNull('email_sent_at')->count());
    }
}
