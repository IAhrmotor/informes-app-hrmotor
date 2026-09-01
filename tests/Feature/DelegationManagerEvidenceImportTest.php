<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Models\SalesforceDelegationManagerHistory;
use App\Models\SalesforceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DelegationManagerEvidenceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_accepts_a_valid_manager_outside_salesforce_users_without_persisting(): void
    {
        $this->catalogDelegation();
        $path = $this->csv([$this->validRow()]);

        try {
            $this->artisan('commissions:import-delegation-manager-evidence', ['file' => $path, '--dry-run' => true])
                ->expectsOutput('Dry-run correcto: 1 filas validas.')
                ->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseMissing('salesforce_delegation_manager_history', [
            'source_key' => 'confirmed-close:a01000000000001:2026-07',
        ]);
        $this->assertDatabaseMissing('salesforce_users', ['salesforce_id' => '005000000000999']);
    }

    public function test_import_persists_an_area_manager_as_store_manager_without_creating_users_or_changing_permissions(): void
    {
        $this->catalogDelegation();
        $areaManager = ReportUser::query()->create([
            'name' => 'Kosta',
            'email' => 'kosta-import-test@example.test',
            'password' => 'secret123',
            'role' => ReportUser::ROLE_AREA_MANAGER,
            'salesforce_user_id' => '005000000000999',
            'area_zone' => 'north',
            'permissions' => [],
            'is_active' => true,
        ]);
        $reportUserCount = ReportUser::query()->count();
        $path = $this->csv([$this->validRow(['manager_name' => 'Kosta'])]);

        try {
            $this->artisan('commissions:import-delegation-manager-evidence', ['file' => $path])
                ->expectsOutput('Import completado: 1 evidencias registradas.')
                ->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas('salesforce_delegation_manager_history', [
            'source_key' => 'confirmed-close:a01000000000001:2026-07',
            'manager_salesforce_user_id' => '005000000000999',
            'manager_name' => 'Kosta',
            'history_verified' => false,
        ]);
        $this->assertSame($reportUserCount, ReportUser::query()->count());
        $this->assertSame(ReportUser::ROLE_AREA_MANAGER, $areaManager->fresh()->role);
        $this->assertSame([], $areaManager->fresh()->permissions);
        $this->assertDatabaseMissing('salesforce_users', ['salesforce_id' => '005000000000999']);
    }

    public function test_import_also_accepts_a_manager_already_present_in_salesforce_users(): void
    {
        $this->catalogDelegation();
        SalesforceUser::query()->create(['salesforce_id' => '005000000000999', 'name' => 'Responsable existente']);
        $path = $this->csv([$this->validRow()]);

        try {
            $this->artisan('commissions:import-delegation-manager-evidence', ['file' => $path])->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas('salesforce_delegation_manager_history', [
            'source_key' => 'confirmed-close:a01000000000001:2026-07',
            'manager_salesforce_user_id' => '005000000000999',
        ]);
    }

    public function test_a_blocking_csv_error_keeps_the_import_atomic(): void
    {
        $this->catalogDelegation();
        $path = $this->csv([
            $this->validRow(),
            $this->validRow(['delegation_name' => 'Otra', 'manager_id' => 'invalid-id']),
        ]);

        try {
            $this->artisan('commissions:import-delegation-manager-evidence', ['file' => $path])
                ->expectsOutputToContain('manager_id no es un ID Salesforce valido')
                ->expectsOutput('Import cancelado: ninguna fila ha sido persistida.')
                ->assertFailed();
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseMissing('salesforce_delegation_manager_history', [
            'source_key' => 'confirmed-close:a01000000000001:2026-07',
        ]);
    }

    private function catalogDelegation(): void
    {
        SalesforceDelegationManagerHistory::query()->create([
            'source_key' => 'catalog:alicante',
            'delegation_salesforce_id' => 'a01000000000001',
            'delegation_name' => 'Alicante',
            'delegation_key' => 'alicante',
            'effective_at' => '2026-08-01 00:00:00',
            'coverage_from' => '2026-08-01 00:00:00',
            'coverage_to' => '2026-08-02 00:00:00',
            'observed_at' => '2026-08-01 00:00:00',
            'source' => 'daily_observation',
            'history_verified' => false,
        ]);
    }

    /** @param array<string, string> $overrides */
    private function validRow(array $overrides = []): array
    {
        return array_merge([
            'delegation_id' => 'a01000000000001',
            'delegation_name' => 'Alicante',
            'manager_id' => '005000000000999',
            'manager_name' => 'Responsable externo',
            'month' => '2026-07',
            'source' => 'direction_confirmation',
            'reference' => 'ACTA-JULIO-2026',
            'recorded_by' => 'it-audit',
            'evidence_type' => 'month_end',
        ], $overrides);
    }

    /** @param array<int, array<string, string>> $rows */
    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'delegation-manager-');
        $handle = fopen($path, 'wb');
        $headers = ['delegation_id', 'delegation_name', 'manager_id', 'manager_name', 'month', 'source', 'reference', 'recorded_by', 'evidence_type'];
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header): string => $row[$header] ?? '', $headers));
        }
        fclose($handle);

        return $path;
    }
}
