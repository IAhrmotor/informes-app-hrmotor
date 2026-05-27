<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_platform_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('unique_key', 64)->unique();
            $table->string('platform')->default('unknown');
            $table->date('metric_date');
            $table->string('account_id')->nullable();
            $table->string('account_name')->nullable();
            $table->string('campaign_id')->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('adset_id')->nullable();
            $table->string('adset_name')->nullable();
            $table->string('ad_group_id')->nullable();
            $table->string('ad_group_name')->nullable();
            $table->string('ad_id')->nullable();
            $table->string('ad_name')->nullable();
            $table->decimal('spend', 14, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('platform_leads')->nullable();
            $table->decimal('platform_conversions', 14, 4)->nullable();
            $table->string('currency')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'metric_date'], 'camp_metric_platform_date_idx');
            $table->index(['platform', 'account_id'], 'camp_metric_platform_account_idx');
            $table->index(['platform', 'campaign_id'], 'camp_metric_platform_campaign_id_idx');
            $table->index(['platform', 'campaign_name'], 'camp_metric_platform_campaign_name_idx');
            $table->index(['platform', 'adset_id'], 'camp_metric_platform_adset_idx');
            $table->index(['platform', 'ad_group_id'], 'camp_metric_platform_adgroup_idx');
            $table->index(['platform', 'ad_id'], 'camp_metric_platform_ad_idx');
            $table->index(['metric_date', 'campaign_id'], 'camp_metric_date_campaign_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_platform_daily_metrics');
    }
};
