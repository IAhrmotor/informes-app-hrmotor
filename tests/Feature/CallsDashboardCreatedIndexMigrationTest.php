<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CallsDashboardCreatedIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_created_index_is_added_and_removed_without_touching_the_existing_created_date_index(): void
    {
        $migration = require database_path('migrations/2026_08_12_090000_add_dashboard_created_index_to_salesforce_calls_table.php');

        $this->assertTrue($this->hasIndex('sf_calls_dashboard_created_idx'));
        $this->assertTrue($this->hasIndex('salesforce_calls_created_date_index'));

        $migration->down();

        $this->assertFalse($this->hasIndex('sf_calls_dashboard_created_idx'));
        $this->assertTrue($this->hasIndex('salesforce_calls_created_date_index'));

        DB::table('salesforce_calls')->insert([
            'salesforce_id' => 'index-plan-included',
            'created_date' => '2026-08-12 10:00:00',
            'included_in_dashboard' => true,
            'created_at' => '2026-08-12 10:00:00',
            'updated_at' => '2026-08-12 10:00:00',
        ]);
        DB::table('salesforce_calls')->insert([
            'salesforce_id' => 'index-plan-excluded',
            'created_date' => '2026-08-12 10:00:00',
            'included_in_dashboard' => false,
            'created_at' => '2026-08-12 10:00:00',
            'updated_at' => '2026-08-12 10:00:00',
        ]);

        $planBefore = collect(DB::select(
            "EXPLAIN QUERY PLAN
             SELECT COUNT(*)
             FROM salesforce_calls
             WHERE included_in_dashboard = 1
               AND created_date >= '2026-08-01 00:00:00'
               AND created_date < '2026-09-01 00:00:00'",
        ))->pluck('detail')->implode(' ');

        $this->assertStringNotContainsString('sf_calls_dashboard_created_idx', $planBefore);

        $migration->up();

        $this->assertTrue($this->hasIndex('sf_calls_dashboard_created_idx'));

        $planAfter = collect(DB::select(
            "EXPLAIN QUERY PLAN
             SELECT COUNT(*)
             FROM salesforce_calls
             WHERE included_in_dashboard = 1
               AND created_date >= '2026-08-01 00:00:00'
               AND created_date < '2026-09-01 00:00:00'",
        ))->pluck('detail')->implode(' ');

        $this->assertStringContainsString('sf_calls_dashboard_created_idx', $planAfter);
    }

    private function hasIndex(string $name): bool
    {
        return collect(DB::select("PRAGMA index_list('salesforce_calls')"))
            ->pluck('name')
            ->contains($name);
    }
}
