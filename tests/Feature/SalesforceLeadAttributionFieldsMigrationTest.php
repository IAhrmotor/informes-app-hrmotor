<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesforceLeadAttributionFieldsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_can_roll_back_and_migrate_again(): void
    {
        $migration = require database_path('migrations/2026_09_01_090000_add_salesforce_lead_origin_and_utm_fields.php');
        $columns = [
            'source_origin_new',
            'medium_origin_new',
            'channel_new',
            'delegation_origin_new',
            'utm_campaign_new',
            'utm_id_new',
            'utm_source_new',
            'utm_medium_new',
            'utm_content_new',
            'acquired_source_legacy',
            'acquired_medium_legacy',
            'field_resolution',
        ];

        foreach (['salesforce_leads', 'campaign_salesforce_leads'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, $columns));
        }

        $migration->down();

        foreach (['salesforce_leads', 'campaign_salesforce_leads'] as $table) {
            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column));
            }
        }

        $migration->up();

        foreach (['salesforce_leads', 'campaign_salesforce_leads'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, $columns));
        }
    }
}
