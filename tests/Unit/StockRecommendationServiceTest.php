<?php

namespace Tests\Unit;

use App\Models\SalesforceSaleSnapshot;
use App\Models\SalesforceVehicle;
use App\Models\StockDelegation;
use App\Services\Reports\Stock\StockRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recomienda_tres_destinos_explicados_y_excluye_tiendas_sin_capacidad(): void
    {
        $origin = $this->delegation('Origen', 10);
        $full = $this->delegation('Completa', 1);
        $open = $this->delegation('Destino bueno', 10);
        $vehicle = $this->vehicle('01t-origin', $origin, 'Peugeot', '208');
        $this->vehicle('01t-full', $full, 'Peugeot', '208');

        for ($index = 0; $index < 3; $index++) {
            SalesforceSaleSnapshot::query()->create([
                'opportunity_salesforce_id' => '006-rec-'.$index,
                'signed_date' => now()->subDays(10 + $index),
                'stock_delegation_id' => $open->id,
                'vehicle_brand' => 'Peugeot',
                'vehicle_model' => '208',
                'vehicle_segment' => 'Utilitario',
                'vehicle_fuel' => 'Gasolina',
                'sale_price' => 14000,
                'rotation_days' => 40,
                'captured_at' => now(),
            ]);
        }

        $service = app(StockRecommendationService::class);
        $context = $service->prepare(
            SalesforceVehicle::query()->where('is_in_stock', true)->get(),
            SalesforceSaleSnapshot::all(),
            StockDelegation::all(),
        );
        $recommendations = $service->recommend($vehicle, $context);

        $this->assertSame('Destino bueno', $recommendations[0]['delegation']);
        $this->assertNotContains('Completa', collect($recommendations)->pluck('delegation')->all());
        $this->assertNotEmpty($recommendations[0]['reasons']);
        $this->assertSame(3, $recommendations[0]['model_sales']);
        $this->assertSame(10, $recommendations[0]['free_capacity']);
    }

    private function delegation(string $name, int $capacity): StockDelegation
    {
        return StockDelegation::query()->create([
            'canonical_name' => $name,
            'normalized_key' => str($name)->lower()->ascii()->toString(),
            'capacity_total' => $capacity,
            'is_commercial' => true,
        ]);
    }

    private function vehicle(string $id, StockDelegation $delegation, string $brand, string $model): SalesforceVehicle
    {
        return SalesforceVehicle::query()->create([
            'salesforce_id' => $id,
            'brand' => $brand,
            'model' => $model,
            'segment' => 'Utilitario',
            'fuel' => 'Gasolina',
            'sale_price' => 14000,
            'state' => 'Disponible',
            'stock_delegation_id' => $delegation->id,
            'is_in_stock' => true,
        ]);
    }
}
