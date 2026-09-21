<?php

namespace Tests\Unit;

use App\Models\SalesforceSaleSnapshot;
use App\Models\SalesforceVehicle;
use App\Models\StockDelegation;
use App\Services\Reports\Stock\StockDashboardDatasetService;
use App\Services\Reports\Stock\StockDirectedTransferPlanner;
use App\Services\Reports\Stock\StockRecommendationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockDirectedTransferPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_selecciona_disponibles_en_stock_operativos_y_del_origen(): void
    {
        $origin = $this->delegation('Origen', 20);
        $destination = $this->delegation('Destino', 10);
        $other = $this->delegation('Otra', 10);

        foreach (range(1, 4) as $index) {
            $this->vehicle('01t-valid-'.$index, $origin, ['plate' => '100'.$index.'AAA']);
        }
        $this->vehicle('01t-reserved', $origin, ['state' => 'Reservado']);
        $this->vehicle('01t-blocked', $origin, ['state' => 'Bloqueado']);
        $this->vehicle('01t-not-stock', $origin, ['is_in_stock' => false]);
        $this->vehicle('01t-non-operational', $origin, ['brand' => 'Vehículo de prueba']);
        $this->vehicle('01t-other-origin', $other);
        foreach (['Disponible', 'Reservado', 'Bloqueado'] as $index => $state) {
            $this->vehicle('01t-destination-'.$index, $destination, ['state' => $state]);
        }

        $plan = $this->plan($origin, $destination, 7);

        $this->assertNotNull($plan);
        $this->assertSame(7, $plan['requested_units']);
        $this->assertSame(4, $plan['proposed_units']);
        $this->assertSame(3, $plan['missing_units']);
        $this->assertEqualsCanonicalizing(
            ['01t-valid-1', '01t-valid-2', '01t-valid-3', '01t-valid-4'],
            $plan['rows']->pluck('id')->all(),
        );
        $this->assertSame(3, $plan['capacity']['current_stock']);
        $this->assertSame(10, $plan['capacity']['configured']);
        $this->assertSame(7, $plan['capacity']['current_free_places']);
        $this->assertSame(7, $plan['capacity']['projected_stock']);
        $this->assertSame(70.0, $plan['capacity']['projected_occupancy']);
    }

    public function test_score_comercial_precede_a_la_antiguedad_y_los_desempates_son_deterministas(): void
    {
        $origin = $this->delegation('Origen orden', 30);
        $destination = $this->delegation('Destino orden', 30);
        $this->vehicle('01t-young-best', $origin, [
            'plate' => '9000ZZZ',
            'model' => 'Modelo ganador',
            'entry_date' => now()->subDays(30)->toDateString(),
        ]);
        $this->vehicle('01t-old-low', $origin, [
            'plate' => '1000AAA',
            'model' => 'Modelo sin ventas',
            'entry_date' => now()->subDays(120)->toDateString(),
        ]);
        foreach (range(1, 5) as $index) {
            SalesforceSaleSnapshot::query()->create([
                'opportunity_salesforce_id' => '006-directed-score-'.$index,
                'signed_date' => now()->subDays($index)->toDateString(),
                'stock_delegation_id' => $destination->id,
                'vehicle_brand' => 'Peugeot',
                'vehicle_model' => 'Modelo ganador',
                'vehicle_segment' => 'Utilitario',
                'vehicle_fuel' => 'Gasolina',
                'sale_price' => 14000,
                'rotation_days' => 30,
                'is_valid' => true,
                'captured_at' => now(),
            ]);
        }

        $scored = $this->plan($origin, $destination, 2);

        $this->assertSame('01t-young-best', $scored['rows'][0]['id']);
        $this->assertGreaterThan($scored['rows'][1]['score'], $scored['rows'][0]['score']);

        SalesforceSaleSnapshot::query()->delete();
        SalesforceVehicle::query()->delete();
        $this->vehicle('01t-priority', $origin, ['model' => 'A', 'entry_date' => now()->subDays(100)->toDateString()]);
        $this->vehicle('01t-days-40', $origin, ['model' => 'B', 'entry_date' => now()->subDays(40)->toDateString()]);
        $this->vehicle('01t-plate-z', $origin, ['plate' => '9999ZZZ', 'model' => 'C', 'entry_date' => now()->subDays(30)->toDateString()]);
        $this->vehicle('01t-plate-a', $origin, ['plate' => '1111AAA', 'model' => 'D', 'entry_date' => now()->subDays(30)->toDateString()]);
        $this->vehicle('01t-no-plate-b', $origin, ['model' => 'E', 'entry_date' => now()->subDays(20)->toDateString()]);
        $this->vehicle('01t-no-plate-a', $origin, ['model' => 'F', 'entry_date' => now()->subDays(20)->toDateString()]);

        $tied = $this->plan($origin, $destination, 6);

        $this->assertSame([
            '01t-priority',
            '01t-days-40',
            '01t-plate-a',
            '01t-plate-z',
            '01t-no-plate-a',
            '01t-no-plate-b',
        ], $tied['rows']->pluck('id')->all());
    }

    public function test_la_capacidad_no_recorta_destino_sobreocupado_ni_traslado_que_provoca_exceso(): void
    {
        $origin = $this->delegation('Origen capacidad', 20);
        $overCapacity = $this->delegation('Destino ya excedido', 1);
        $induced = $this->delegation('Destino exceso nuevo', 3);
        foreach (range(1, 2) as $index) {
            $this->vehicle('01t-origin-capacity-'.$index, $origin, ['model' => 'Origen '.$index]);
            $this->vehicle('01t-over-current-'.$index, $overCapacity);
            $this->vehicle('01t-induced-current-'.$index, $induced);
        }

        $alreadyOver = $this->plan($origin, $overCapacity, 2);
        $this->assertSame(2, $alreadyOver['proposed_units']);
        $this->assertSame(1, $alreadyOver['capacity']['current_excess']);
        $this->assertSame(3, $alreadyOver['capacity']['projected_excess']);

        $becomesOver = $this->plan($origin, $induced, 2);
        $this->assertSame(2, $becomesOver['proposed_units']);
        $this->assertSame(0, $becomesOver['capacity']['current_excess']);
        $this->assertSame(1, $becomesOver['capacity']['projected_excess']);
    }

    public function test_destino_sin_capacidad_se_evalua_sin_inventar_ocupacion(): void
    {
        $origin = $this->delegation('Origen sin capacidad', 20);
        $destination = $this->delegation('Destino sin capacidad', null);
        $this->vehicle('01t-origin-null-capacity', $origin);
        $this->vehicle('01t-destination-null-capacity', $destination);

        $plan = $this->plan($origin, $destination, 1);

        $this->assertSame(1, $plan['proposed_units']);
        $this->assertNull($plan['capacity']['configured']);
        $this->assertNull($plan['capacity']['current_free_places']);
        $this->assertNull($plan['capacity']['projected_occupancy']);
        $this->assertNull($plan['capacity']['projected_excess']);
        $this->assertSame(2, $plan['capacity']['projected_stock']);
        $this->assertFalse($plan['rows'][0]['has_history']);
        $this->assertContains('Alternativa penalizada por no tener histórico comparable', $plan['rows'][0]['reasons']);

        $destination->update(['capacity_total' => 0]);
        $zeroCapacity = $this->plan($origin, $destination->fresh(), 1);
        $this->assertNull($zeroCapacity['capacity']['configured']);
        $this->assertSame(1, $zeroCapacity['proposed_units']);
    }

    public function test_origen_sin_disponibles_devuelve_propuesta_vacia_sin_rellenar_estados_excluidos(): void
    {
        $origin = $this->delegation('Origen no disponible', 20);
        $destination = $this->delegation('Destino no disponible', 20);
        $this->vehicle('01t-only-reserved', $origin, ['state' => 'Reservado']);
        $this->vehicle('01t-only-blocked', $origin, ['state' => 'Bloqueado']);

        $plan = $this->plan($origin, $destination, 3);

        $this->assertSame(0, $plan['proposed_units']);
        $this->assertSame(3, $plan['missing_units']);
        $this->assertTrue($plan['rows']->isEmpty());
        $this->assertSame(0, $plan['capacity']['projected_stock']);
    }

    public function test_filtros_generales_no_reducen_el_universo_dirigido(): void
    {
        $origin = $this->delegation('Origen filtros', 20);
        $destination = $this->delegation('Destino filtros', 20);
        $this->vehicle('01t-filter-directed-ford', $origin, ['brand' => 'Ford']);
        $this->vehicle('01t-filter-directed-toyota', $origin, ['brand' => 'Toyota']);

        $dataset = app(StockDashboardDatasetService::class)->build([
            'brand' => 'Marca inexistente',
            'state' => 'Reservado',
            'transfer_plan' => '1',
            'transfer_origin_id' => $origin->id,
            'transfer_destination_id' => $destination->id,
            'transfer_units' => 2,
        ], 'recommendations');

        $this->assertSame(2, $dataset['directedTransferPlan']['proposed_units']);
        $this->assertSame(0, $dataset['recommendationTotal']);
        $this->assertTrue($dataset['recommendationRows']->isEmpty());
        $this->assertEqualsCanonicalizing(
            ['01t-filter-directed-ford', '01t-filter-directed-toyota'],
            $dataset['directedTransferPlan']['rows']->pluck('id')->all(),
        );
    }

    private function plan(StockDelegation $origin, StockDelegation $destination, int $units): array
    {
        $stock = SalesforceVehicle::query()->with('delegation')->get();
        $delegations = StockDelegation::query()->get();
        $recommendations = app(StockRecommendationService::class);
        $context = $recommendations->prepare($stock, SalesforceSaleSnapshot::query()->get(), $delegations);

        return app(StockDirectedTransferPlanner::class)->plan(
            $stock,
            $context,
            $origin->id,
            $destination->id,
            $units,
            CarbonImmutable::today(config('app.timezone')),
        );
    }

    private function delegation(string $name, ?int $capacity): StockDelegation
    {
        return StockDelegation::query()->create([
            'canonical_name' => $name,
            'normalized_key' => str($name)->lower()->ascii()->toString(),
            'capacity_total' => $capacity,
            'is_commercial' => true,
        ]);
    }

    private function vehicle(string $id, StockDelegation $delegation, array $attributes = []): SalesforceVehicle
    {
        return SalesforceVehicle::query()->create([
            'salesforce_id' => $id,
            'brand' => 'Peugeot',
            'model' => '208 '.$id,
            'segment' => 'Utilitario',
            'fuel' => 'Gasolina',
            'sale_price' => 14000,
            'state' => 'Disponible',
            'stock_delegation_id' => $delegation->id,
            'entry_date' => now()->subDays(30)->toDateString(),
            'is_in_stock' => true,
            ...$attributes,
        ]);
    }
}
