<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Services\Reports\CommercialCommissions\CommercialCommissionSourceReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialCommissionSourceReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_zero_record_completed_sync_is_valid_readiness(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities', ['queried' => 0, 'stored' => 0]);
        $this->completedRun('salesforce_tasaciones', ['queried' => 0, 'stored' => 0]);

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('call_center', '2026-07', ['issues' => []]);

        $this->assertTrue($state['ready']);
        $this->assertSame('ready', data_get($state, 'components.sales.status'));
        $this->assertSame('ready', data_get($state, 'components.tasaciones.status'));
    }

    public function test_delegation_reviews_use_internal_endpoint_status_not_salesforce_reviews(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities');

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('delegations', '2026-07', [
            'issues' => [],
            'delegation_rows' => [[
                'delegation_name' => 'Alicante',
                'reviews_technical_status' => 'available',
                'reviews_fetched_at' => now()->toIso8601String(),
            ]],
        ]);

        $this->assertTrue($state['ready']);
        $this->assertSame('ready', data_get($state, 'components.reviews.status'));
        $this->assertArrayNotHasKey('salesforce_reviews', $state['source_state']);
    }

    public function test_failed_internal_reviews_endpoint_blocks_delegation_preparation(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities');

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('delegations', '2026-07', [
            'issues' => [],
            'delegation_rows' => [[
                'delegation_name' => 'Alicante',
                'reviews_technical_status' => 'unavailable',
                'reviews_fetched_at' => null,
            ]],
        ]);

        $this->assertFalse($state['ready']);
        $this->assertTrue(data_get($state, 'components.reviews.blocking'));
        $this->assertStringContainsString('endpoint interno', data_get($state, 'components.reviews.message'));
    }

    public function test_legacy_manager_coverage_warning_is_hidden_without_losing_source_warnings(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities');
        $legacyWarning = 'El responsable al cierre de Alicante está confirmado, pero no existe histórico suficiente para verificar si hubo rotaciones durante July de 2026.';
        $sourceWarning = 'La caché del endpoint interno no tiene una verificación reciente.';

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('delegations', '2026-07', [
            'issues' => [],
            'warnings' => [$legacyWarning, $sourceWarning],
            'delegation_rows' => [[
                'delegation_name' => 'Alicante',
                'store_manager_distinct_count' => 1,
                'store_manager_alert' => $legacyWarning,
                'reviews_technical_status' => 'available',
                'reviews_fetched_at' => now()->toIso8601String(),
            ]],
        ]);

        $this->assertNotContains($legacyWarning, $state['warnings']);
        $this->assertContains($sourceWarning, $state['warnings']);
    }

    public function test_manager_rotation_warning_is_kept_only_above_two_distinct_managers(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities');
        $rotationWarning = 'Alicante ha tenido 3 jefes de tienda demostrados durante julio de 2026. Revisar la asignación de la comisión.';

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('delegations', '2026-07', [
            'issues' => [],
            'warnings' => [$rotationWarning],
            'delegation_rows' => [[
                'delegation_name' => 'Alicante',
                'store_manager_distinct_count' => 3,
                'store_manager_alert' => $rotationWarning,
                'reviews_technical_status' => 'available',
                'reviews_fetched_at' => now()->toIso8601String(),
            ]],
        ]);

        $this->assertContains($rotationWarning, $state['warnings']);
        $this->assertTrue($state['ready']);
    }

    private function completedRun(string $dataset, array $stats = ['queried' => 1, 'stored' => 1]): void
    {
        ReportSyncRun::query()->create([
            'dataset' => $dataset,
            'source' => 'salesforce',
            'status' => 'completed',
            'period_start_at' => '2026-07-01 00:00:00',
            'period_end_at' => '2026-08-01 00:00:00',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'timezone' => config('app.timezone'),
            'stats' => $stats,
        ]);
    }
}
