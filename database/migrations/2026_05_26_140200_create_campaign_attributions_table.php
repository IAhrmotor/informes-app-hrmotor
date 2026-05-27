<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_attributions', function (Blueprint $table) {
            $table->id();
            $table->string('lead_id')->unique();
            $table->string('opportunity_id')->nullable()->unique();
            $table->string('platform')->nullable()->index('camp_attr_platform_idx');
            $table->string('account_id')->nullable();
            $table->string('campaign_id')->nullable()->index('camp_attr_campaign_id_idx');
            $table->string('campaign_name')->nullable()->index('camp_attr_campaign_name_idx');
            $table->string('campaign_name_key')->nullable()->index('camp_attr_campaign_name_key_idx');
            $table->string('source_acquired')->nullable();
            $table->string('medium_acquired')->nullable();
            $table->string('campaign_acquired')->nullable();
            $table->string('acquired_id')->nullable();
            $table->string('acquired_id_key')->nullable()->index('camp_attr_acquired_id_key_idx');
            $table->string('content_acquired')->nullable();
            $table->string('content_acquired_key')->nullable()->index('camp_attr_content_key_idx');
            $table->string('vehicle_interest')->nullable();
            $table->string('lead_status')->nullable();
            $table->dateTime('lead_created_at')->nullable()->index('camp_attr_lead_created_idx');
            $table->dateTime('opportunity_created_at')->nullable();
            $table->date('reservation_date')->nullable();
            $table->date('sale_date')->nullable();
            $table->decimal('sale_amount', 14, 2)->nullable();
            $table->boolean('has_opportunity')->default(false)->index('camp_attr_has_opp_idx');
            $table->boolean('has_reservation')->default(false)->index('camp_attr_has_res_idx');
            $table->boolean('has_fallen_reservation')->default(false)->index('camp_attr_has_fallen_res_idx');
            $table->boolean('has_sale')->default(false)->index('camp_attr_has_sale_idx');
            $table->string('lead_delegation')->nullable()->index('camp_attr_lead_deleg_idx');
            $table->string('lead_zone')->nullable()->index('camp_attr_lead_zone_idx');
            $table->string('commercial_user_id')->nullable()->index('camp_attr_commercial_id_idx');
            $table->string('commercial_user_name')->nullable()->index('camp_attr_commercial_name_idx');
            $table->string('attribution_method')->nullable();
            $table->string('attribution_confidence')->nullable();
            $table->integer('attribution_window_days')->default(30);
            $table->timestamps();

            $table->index(['platform', 'account_id'], 'camp_attr_platform_account_idx');
            $table->index(['platform', 'campaign_id'], 'camp_attr_platform_campaign_idx');
            $table->index(['lead_created_at', 'campaign_id'], 'camp_attr_date_campaign_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_attributions');
    }
};
