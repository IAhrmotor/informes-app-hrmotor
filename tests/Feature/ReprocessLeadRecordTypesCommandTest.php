<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReprocessLeadRecordTypesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_cuenta_y_el_reproceso_es_idempotente(): void
    {
        $lead = $this->lead('00Q-lead', 'Lead', 'lead');
        $ayvens = $this->lead('00Q-ayvens', 'Ayvens', 'ayvens');
        $correct = $this->lead('00Q-venta', 'Venta', 'venta');

        $this->artisan('reports:reprocess-lead-record-types', ['--dry-run' => true])
            ->expectsOutput('Registros examinados: 3')
            ->expectsOutput('Registros que cambiarían: 2')
            ->expectsOutput('Lead -> Venta: 1')
            ->expectsOutput('Ayvens -> Venta: 1')
            ->assertSuccessful();
        $this->assertSame('lead', $lead->fresh()->record_type_normalized);
        $this->assertSame('ayvens', $ayvens->fresh()->record_type_normalized);

        $this->artisan('reports:reprocess-lead-record-types')->assertSuccessful();
        $this->assertSame('venta', $lead->fresh()->record_type_normalized);
        $this->assertSame('venta', $ayvens->fresh()->record_type_normalized);
        $this->assertSame('venta', $correct->fresh()->record_type_normalized);
        $this->artisan('reports:reprocess-lead-record-types')->expectsOutput('Registros que cambiarían: 0')->assertSuccessful();
    }

    private function lead(string $id, string $raw, string $normalized): SalesforceLead
    {
        return SalesforceLead::query()->create([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2020-01-01 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => $raw,
            'record_type_normalized' => $normalized,
        ]);
    }
}
