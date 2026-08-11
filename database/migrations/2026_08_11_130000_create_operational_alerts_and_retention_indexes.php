<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RAW_PAYLOAD_INDEXES = [
        'salesforce_users' => 'sf_users_payload_retention_idx',
        'salesforce_activities' => 'sf_activities_payload_ret_idx',
        'salesforce_calls' => 'sf_calls_payload_retention_idx',
        'campaign_platform_daily_metrics' => 'camp_metrics_payload_ret_idx',
        'salesforce_reviews' => 'sf_reviews_payload_retention_idx',
        'salesforce_vehicles' => 'sf_vehicles_payload_retention_idx',
        'salesforce_logistics' => 'sf_logistics_payload_ret_idx',
        'campaign_platform_identifiers' => 'camp_ident_payload_ret_idx',
    ];

    public function up(): void
    {
        Schema::create('operational_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('type', 80);
            $table->string('severity', 20);
            $table->string('source', 120);
            $table->string('state', 20)->default('open');
            $table->text('message');
            $table->string('technical_identifier', 190);
            $table->json('context')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->unsignedInteger('occurrences')->default(1);
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'severity', 'last_detected_at'], 'operational_alerts_panel_idx');
            $table->index(['state', 'resolved_at', 'id'], 'operational_alerts_retention_idx');
        });

        foreach (self::RAW_PAYLOAD_INDEXES as $tableName => $indexName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->index(['updated_at', 'id'], $indexName));
        }

        Schema::table('report_sync_runs', fn (Blueprint $table) => $table->index(
            ['status', 'completed_at', 'id'],
            'report_sync_runs_retention_idx',
        ));
        Schema::table('stock_availability_alerts', fn (Blueprint $table) => $table->index(
            ['state', 'resolved_at', 'id'],
            'stock_alerts_retention_idx',
        ));
        Schema::table('failed_jobs', fn (Blueprint $table) => $table->index(
            ['failed_at', 'id'],
            'failed_jobs_retention_idx',
        ));
    }

    public function down(): void
    {
        Schema::table('failed_jobs', fn (Blueprint $table) => $table->dropIndex('failed_jobs_retention_idx'));
        Schema::table('stock_availability_alerts', fn (Blueprint $table) => $table->dropIndex('stock_alerts_retention_idx'));
        Schema::table('report_sync_runs', fn (Blueprint $table) => $table->dropIndex('report_sync_runs_retention_idx'));

        foreach (array_reverse(self::RAW_PAYLOAD_INDEXES, true) as $tableName => $indexName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($indexName));
        }

        Schema::dropIfExists('operational_alerts');
    }
};
