<?php

namespace Tests\Unit;

use App\Models\SalesforceVehicle;
use App\Services\Reports\Stock\StockCatalogNormalizer;
use Illuminate\Support\Collection;
use Tests\TestCase;

class StockCatalogNormalizerTest extends TestCase
{
    public function test_agrupa_variantes_y_excluye_valores_no_operativos(): void
    {
        $normalizer = app(StockCatalogNormalizer::class);
        $vehicles = new Collection([
            new SalesforceVehicle(['salesforce_id' => '01t-1', 'brand' => 'Peugeot']),
            new SalesforceVehicle(['salesforce_id' => '01t-2', 'brand' => ' PEUGEOT ']),
            new SalesforceVehicle(['salesforce_id' => '01t-3', 'model' => 'Vehículo de formación']),
        ]);

        $duplicates = $normalizer->duplicateGroups($vehicles);
        $excluded = $normalizer->excludedVehicles($vehicles);

        $this->assertCount(1, $duplicates);
        $this->assertSame('brand', $duplicates->first()['dimension']);
        $this->assertSame('peugeot', $duplicates->first()['normalized_key']);
        $this->assertSame(['01t-3'], $excluded->pluck('salesforce_id')->all());
    }
}
