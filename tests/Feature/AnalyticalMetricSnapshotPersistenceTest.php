<?php

namespace Tests\Feature;

use App\Models\AnalyticalMetricSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalyticalMetricSnapshotPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_schema_indexes_unique_identity_and_decimal_casts(): void
    {
        $this->assertTrue(Schema::hasColumns('analytical_metric_snapshots', [
            'module_key', 'metric_key', 'metric_label', 'source_key', 'source_identifier', 'source_identifier_hash',
            'scope_key', 'value_format', 'data_date', 'source_cutoff_at', 'current_value', 'd7_value', 'd14_value',
            'd21_value', 'd28_value', 'reference_count', 'baseline_value', 'absolute_change', 'relative_change',
            'd364_value', 'year_absolute_change', 'year_relative_change', 'is_evaluable', 'evaluation_reason',
            'engine_version', 'computed_at',
        ]));
        $indexes = collect(DB::select("PRAGMA index_list('analytical_metric_snapshots')"))->pluck('name');
        $this->assertContains('analytics_snap_identity_uq', $indexes);
        $this->assertContains('analytics_snap_module_date_idx', $indexes);
        $this->assertContains('analytics_snap_module_metric_date_idx', $indexes);
        $this->assertContains('analytics_snap_source_hash_date_idx', $indexes);
        $this->assertContains('analytics_snap_eval_date_idx', $indexes);

        $snapshot = AnalyticalMetricSnapshot::query()->create($this->row());
        $snapshot = $snapshot->fresh();
        $this->assertSame('0.00002600', $snapshot->current_value);
        $this->assertSame('0.00000400', $snapshot->d7_value);
        $this->assertTrue($snapshot->is_evaluable);
        $this->assertSame('2026-08-16', $snapshot->data_date->toDateString());

        $migrationSource = file_get_contents(database_path('migrations/2026_08_19_090000_create_analytical_metric_snapshots_table.php'));
        foreach (['current_value', 'd7_value', 'd14_value', 'd21_value', 'd28_value', 'baseline_value', 'absolute_change', 'd364_value', 'year_absolute_change'] as $column) {
            $this->assertStringContainsString("decimal('{$column}', 24, 8)->nullable()", $migrationSource);
        }
        foreach (['relative_change', 'year_relative_change'] as $column) {
            $this->assertStringContainsString("decimal('{$column}', 20, 8)->nullable()", $migrationSource);
        }
        $this->assertStringContainsString("unsignedTinyInteger('reference_count')", $migrationSource);
        $this->assertStringContainsString("boolean('is_evaluable')", $migrationSource);
    }

    public function test_migration_down_only_removes_snapshot_table(): void
    {
        $migration = require database_path('migrations/2026_08_19_090000_create_analytical_metric_snapshots_table.php');
        $migration->down();

        $this->assertFalse(Schema::hasTable('analytical_metric_snapshots'));
        $this->assertTrue(Schema::hasTable('seo_search_console_daily_metrics'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('analytical_metric_snapshots'));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'module_key' => 'seo', 'metric_key' => 'ga4_organic_key_events', 'metric_label' => 'GA4',
            'source_key' => 'ga4', 'source_identifier' => '123', 'source_identifier_hash' => hash('sha256', '123'),
            'scope_key' => 'ESP', 'value_format' => 'decimal', 'data_date' => '2026-08-16',
            'source_cutoff_at' => '2026-08-16 00:00:00', 'current_value' => '0.00002600',
            'd7_value' => '0.00000400', 'd14_value' => null, 'd21_value' => null, 'd28_value' => null,
            'reference_count' => 3, 'baseline_value' => '0.00002600', 'absolute_change' => '0.00000000',
            'relative_change' => '0.00000000', 'd364_value' => null, 'year_absolute_change' => null,
            'year_relative_change' => null, 'is_evaluable' => true, 'evaluation_reason' => null,
            'engine_version' => 'same_weekday_v1', 'computed_at' => now(),
        ];
    }
}
