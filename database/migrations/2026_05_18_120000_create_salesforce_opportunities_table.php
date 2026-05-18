<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('salesforce_id')->unique();
            $table->string('name')->nullable();
            $table->dateTime('created_date')->nullable()->index('sf_opps_created_idx');
            $table->date('close_date')->nullable();
            $table->string('stage_name')->nullable()->index('sf_opps_stage_idx');
            $table->string('record_type_name')->nullable()->index('sf_opps_record_type_idx');
            $table->string('owner_id')->nullable()->index('sf_opps_owner_idx');
            $table->string('owner_name')->nullable();
            $table->string('owner_delegation')->nullable()->index('sf_opps_owner_deleg_idx');
            $table->string('account_id')->nullable()->index('sf_opps_account_idx');
            $table->string('account_name')->nullable();
            $table->string('account_phone')->nullable()->index('sf_opps_account_phone_idx');
            $table->string('account_person_email')->nullable()->index('sf_opps_person_email_idx');
            $table->string('account_company_email')->nullable()->index('sf_opps_company_email_idx');
            $table->string('portal_original')->nullable()->index('sf_opps_portal_orig_idx');
            $table->string('portal_resolved')->nullable()->index('sf_opps_portal_res_idx');
            $table->string('portal_resolution_source')->nullable()->index('sf_opps_portal_src_idx');
            $table->string('portal_resolution_lead_id')->nullable()->index('sf_opps_portal_lead_idx');
            $table->json('portal_resolution_debug')->nullable();
            $table->boolean('reservation')->default(false)->index('sf_opps_reservation_idx');
            $table->date('reservation_date')->nullable()->index('sf_opps_reservation_date_idx');
            $table->boolean('cv_signed')->default(false)->index('sf_opps_cv_signed_idx');
            $table->date('cv_signed_date')->nullable()->index('sf_opps_cv_signed_date_idx');
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_opportunities');
    }
};
