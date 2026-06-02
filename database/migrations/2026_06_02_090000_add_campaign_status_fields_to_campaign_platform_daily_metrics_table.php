<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_platform_daily_metrics', function (Blueprint $table): void {
            if (! Schema::hasColumn('campaign_platform_daily_metrics', 'campaign_status')) {
                $table->string('campaign_status')->nullable()->after('campaign_name')->index('camp_metric_campaign_status_idx');
            }

            if (! Schema::hasColumn('campaign_platform_daily_metrics', 'campaign_effective_status')) {
                $table->string('campaign_effective_status')->nullable()->after('campaign_status')->index('camp_metric_campaign_effective_status_idx');
            }

            if (! Schema::hasColumn('campaign_platform_daily_metrics', 'campaign_start_date')) {
                $table->date('campaign_start_date')->nullable()->after('campaign_effective_status');
            }

            if (! Schema::hasColumn('campaign_platform_daily_metrics', 'campaign_end_date')) {
                $table->date('campaign_end_date')->nullable()->after('campaign_start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaign_platform_daily_metrics', function (Blueprint $table): void {
            foreach ([
                'campaign_status' => 'camp_metric_campaign_status_idx',
                'campaign_effective_status' => 'camp_metric_campaign_effective_status_idx',
            ] as $column => $index) {
                if (Schema::hasColumn('campaign_platform_daily_metrics', $column)) {
                    $table->dropIndex($index);
                }
            }

            foreach ([
                'campaign_end_date',
                'campaign_start_date',
                'campaign_effective_status',
                'campaign_status',
            ] as $column) {
                if (Schema::hasColumn('campaign_platform_daily_metrics', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
