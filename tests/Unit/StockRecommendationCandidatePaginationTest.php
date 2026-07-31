<?php

namespace Tests\Unit;

use App\Models\SalesforceVehicle;
use App\Models\StockDelegation;
use App\Services\Reports\Stock\StockDashboardDatasetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockRecommendationCandidatePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_calcula_todos_los_candidatos_antes_de_paginar(): void
    {
        $origin = $this->delegation('Origen', 500);
        foreach (['Destino 1', 'Destino 2', 'Destino 3'] as $destination) {
            $this->delegation($destination, 500);
        }
        $this->delegation('Dos Hermanas', 500);
        $this->delegation('Sin capacidad', null);
        $this->delegation('Capacidad cero', 0);

        $now = now();
        $rows = [];
        foreach (range(1, 260) as $index) {
            $rows[] = [
                'salesforce_id' => '01t-candidate-'.$index,
                'brand' => 'Peugeot',
                'model' => '208',
                'segment' => 'Utilitario',
                'fuel' => 'Gasolina',
                'state' => 'Disponible',
                'stock_delegation_id' => $origin->id,
                'sale_price' => 14000,
                'entry_date' => $now->copy()->subDays(61)->toDateString(),
                'is_in_stock' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (['Reservado', 'Bloqueado'] as $index => $state) {
            $rows[] = [
                'salesforce_id' => '01t-excluded-'.$index,
                'brand' => 'Peugeot',
                'model' => '208',
                'segment' => 'Utilitario',
                'fuel' => 'Gasolina',
                'state' => $state,
                'stock_delegation_id' => $origin->id,
                'sale_price' => 14000,
                'entry_date' => $now->copy()->subDays(120)->toDateString(),
                'is_in_stock' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        SalesforceVehicle::query()->insert($rows);

        $firstPage = app(StockDashboardDatasetService::class)->build([], 'recommendations');
        $secondPage = app(StockDashboardDatasetService::class)->build(
            ['recommendation_page' => 2],
            'recommendations',
        );

        $this->assertSame(260, $firstPage['recommendationAvailableTotal']);
        $this->assertSame(260, $firstPage['recommendationTotal']);
        $this->assertSame(150, $firstPage['recommendationDisplayed']);
        $this->assertSame(2, $firstPage['recommendationPages']);
        $this->assertSame(110, $secondPage['recommendationDisplayed']);
        $this->assertTrue($firstPage['recommendationRows']->every(fn (array $row): bool => $row['state'] === 'Disponible'));
        $this->assertTrue($firstPage['recommendationRows']->every(fn (array $row): bool => count($row['recommendations']) === 3));
        $this->assertTrue($firstPage['recommendationRows']->every(function (array $row): bool {
            $destinations = collect($row['recommendations'])->pluck('delegation');

            return ! $destinations->contains('Origen')
                && ! $destinations->contains('Dos Hermanas')
                && ! $destinations->contains('Sin capacidad')
                && ! $destinations->contains('Capacidad cero');
        }));
    }

    public function test_calcula_un_volumen_equivalente_a_produccion_sin_agotar_el_tiempo_de_peticion(): void
    {
        $origin = $this->delegation('Origen volumen', 5000);
        foreach (range(1, 20) as $index) {
            $this->delegation('Destino volumen '.$index, 5000);
        }

        $now = now();
        $rows = [];
        foreach (range(1, 2600) as $index) {
            $profile = $index;
            $rows[] = [
                'salesforce_id' => '01t-volume-'.$index,
                'brand' => 'Marca '.($profile % 20),
                'model' => 'Modelo '.$profile,
                'segment' => 'Segmento '.($profile % 6),
                'fuel' => 'Combustible '.($profile % 4),
                'state' => 'Disponible',
                'stock_delegation_id' => $origin->id,
                'sale_price' => 10000 + (($profile % 8) * 5000),
                'entry_date' => $now->copy()->subDays(61 + ($index % 90))->toDateString(),
                'is_in_stock' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        SalesforceVehicle::query()->insert($rows);

        $startedAt = hrtime(true);
        $dataset = app(StockDashboardDatasetService::class)->build([], 'recommendations');
        $durationSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->assertSame(2600, $dataset['recommendationTotal']);
        $this->assertSame(150, $dataset['recommendationDisplayed']);
        $this->assertLessThan(20, $durationSeconds, "El cálculo tardó {$durationSeconds} segundos");
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
}
