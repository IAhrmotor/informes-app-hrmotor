<?php

namespace Tests\Feature;

use App\Models\SeoGa4OrganicDailyMetric;
use App\Models\SeoGa4OrganicKeyEventDailyMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeoGa4PersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ga4_tables_constraints_indexes_and_decimal_casts_are_available(): void
    {
        $this->assertTrue(Schema::hasColumns('seo_ga4_organic_daily_metrics', [
            'property_id', 'data_date', 'country_scope', 'key_events', 'source_timezone', 'extracted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('seo_ga4_organic_key_event_daily_metrics', [
            'property_id', 'data_date', 'country_scope', 'event_name', 'event_hash', 'key_events', 'source_timezone', 'extracted_at',
        ]));

        $dailyIndexes = collect(DB::select("PRAGMA index_list('seo_ga4_organic_daily_metrics')"))->pluck('name');
        $eventIndexes = collect(DB::select("PRAGMA index_list('seo_ga4_organic_key_event_daily_metrics')"))->pluck('name');
        $this->assertContains('seo_ga4_daily_identity_uq', $dailyIndexes);
        $this->assertContains('seo_ga4_daily_date_idx', $dailyIndexes);
        $this->assertContains('seo_ga4_daily_property_date_idx', $dailyIndexes);
        $this->assertContains('seo_ga4_daily_scope_date_idx', $dailyIndexes);
        $this->assertContains('seo_ga4_event_identity_uq', $eventIndexes);
        $this->assertContains('seo_ga4_event_property_date_idx', $eventIndexes);
        $this->assertContains('seo_ga4_event_scope_date_idx', $eventIndexes);

        $daily = SeoGa4OrganicDailyMetric::query()->create([
            'property_id' => '123', 'data_date' => '2026-08-14', 'country_scope' => 'ESP',
            'key_events' => '1.375000', 'source_timezone' => 'Europe/Madrid', 'extracted_at' => now(),
        ]);
        $event = SeoGa4OrganicKeyEventDailyMetric::query()->create([
            'property_id' => '123', 'data_date' => '2026-08-14', 'country_scope' => 'ESP',
            'event_name' => 'form_submit', 'event_hash' => hash('sha256', 'form_submit'),
            'key_events' => '0.250000', 'source_timezone' => 'Europe/Madrid', 'extracted_at' => now(),
        ]);

        $this->assertSame('1.375000', $daily->fresh()->key_events);
        $this->assertSame('0.250000', $event->fresh()->key_events);
        $this->assertNotSame(1, $daily->fresh()->key_events);
    }

    public function test_each_ga4_migration_down_removes_only_its_own_table(): void
    {
        $dailyMigration = require database_path('migrations/2026_08_17_100000_create_seo_ga4_organic_daily_metrics_table.php');
        $dailyMigration->down();
        $this->assertFalse(Schema::hasTable('seo_ga4_organic_daily_metrics'));
        $this->assertTrue(Schema::hasTable('seo_ga4_organic_key_event_daily_metrics'));
        $dailyMigration->up();

        $eventMigration = require database_path('migrations/2026_08_17_100100_create_seo_ga4_organic_key_event_daily_metrics_table.php');
        $eventMigration->down();
        $this->assertTrue(Schema::hasTable('seo_ga4_organic_daily_metrics'));
        $this->assertFalse(Schema::hasTable('seo_ga4_organic_key_event_daily_metrics'));
        $eventMigration->up();
    }
}
