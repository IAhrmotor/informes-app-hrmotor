<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_salesforce_leads', function (Blueprint $table) {
            $table->id();
            $table->string('salesforce_id')->unique();
            $table->string('name')->nullable();
            $table->dateTime('created_date')->nullable()->index('campaign_sf_leads_created_idx');
            $table->string('status')->nullable()->index('campaign_sf_leads_status_idx');
            $table->string('owner_id')->nullable()->index('campaign_sf_leads_owner_idx');
            $table->string('owner_name')->nullable();
            $table->string('phone')->nullable()->index('campaign_sf_leads_phone_idx');
            $table->string('mobile_phone')->nullable()->index('campaign_sf_leads_mobile_phone_idx');
            $table->string('email')->nullable()->index('campaign_sf_leads_email_idx');
            $table->boolean('is_converted')->default(false)->index('campaign_sf_leads_converted_idx');
            $table->dateTime('converted_date')->nullable()->index('campaign_sf_leads_converted_date_idx');
            $table->string('converted_account_id')->nullable();
            $table->string('converted_contact_id')->nullable();
            $table->string('converted_opportunity_id')->nullable()->index('campaign_sf_leads_converted_opp_idx');
            $table->string('fuente_origen')->nullable()->index('campaign_sf_leads_source_idx');
            $table->string('medio_origen')->nullable()->index('campaign_sf_leads_medium_idx');
            $table->string('campaign_acquired')->nullable()->index('campaign_sf_leads_campaign_idx');
            $table->string('acquired_id')->nullable()->index('campaign_sf_leads_acquired_id_idx');
            $table->string('content_acquired')->nullable()->index('campaign_sf_leads_content_idx');
            $table->string('vehicle_interest')->nullable();
            $table->string('delegacion_encargada_text')->nullable()->index('campaign_sf_leads_deleg_text_idx');
            $table->string('delegacion_encargada_id')->nullable();
            $table->string('delegacion_encargada_bueno')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_salesforce_leads');
    }
};
