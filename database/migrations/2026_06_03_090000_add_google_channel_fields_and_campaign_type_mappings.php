<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_platform_daily_metrics', function (Blueprint $table): void {
            if (! Schema::hasColumn('campaign_platform_daily_metrics', 'advertising_channel_type')) {
                $table->string('advertising_channel_type')->nullable()->after('campaign_end_date')->index('camp_metric_channel_type_idx');
            }

            if (! Schema::hasColumn('campaign_platform_daily_metrics', 'advertising_channel_sub_type')) {
                $table->string('advertising_channel_sub_type')->nullable()->after('advertising_channel_type');
            }
        });

        Schema::create('campaign_type_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('platform')->nullable()->index('campaign_type_platform_idx');
            $table->string('campaign_id')->nullable()->index('campaign_type_campaign_id_idx');
            $table->string('campaign_name')->nullable()->index('campaign_type_campaign_name_idx');
            $table->string('campaign_type', 20)->default('venta')->index('campaign_type_type_idx');
            $table->boolean('active')->default(true)->index('campaign_type_active_idx');
            $table->timestamps();

            $table->unique(['platform', 'campaign_id', 'campaign_name'], 'campaign_type_mapping_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_type_mappings');

        Schema::table('campaign_platform_daily_metrics', function (Blueprint $table): void {
            if (Schema::hasColumn('campaign_platform_daily_metrics', 'advertising_channel_sub_type')) {
                $table->dropColumn('advertising_channel_sub_type');
            }

            if (Schema::hasColumn('campaign_platform_daily_metrics', 'advertising_channel_type')) {
                $table->dropIndex('camp_metric_channel_type_idx');
                $table->dropColumn('advertising_channel_type');
            }
        });
    }
};
