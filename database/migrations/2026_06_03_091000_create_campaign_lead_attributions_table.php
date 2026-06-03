<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_lead_attributions', function (Blueprint $table): void {
            $table->id();
            $table->string('lead_id')->unique();
            $table->dateTime('lead_created_date')->nullable()->index('campaign_lead_attr_lead_date_idx');
            $table->string('campaign_name')->nullable()->index('campaign_lead_attr_campaign_name_idx');
            $table->string('campaign_id')->nullable()->index('campaign_lead_attr_campaign_id_idx');
            $table->string('platform')->nullable()->index('campaign_lead_attr_platform_idx');
            $table->string('campaign_type', 20)->default('venta')->index('campaign_lead_attr_type_idx');
            $table->string('opportunity_id')->nullable()->index('campaign_lead_attr_opportunity_idx');
            $table->boolean('has_reservation')->default(false);
            $table->boolean('has_sale')->default(false);
            $table->boolean('has_purchase')->default(false);
            $table->decimal('sold_amount', 14, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_lead_attributions');
    }
};
