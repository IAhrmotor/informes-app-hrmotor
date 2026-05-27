<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->string('campaign_acquired')->nullable()->after('medio_origen')->index('sf_leads_campaign_acq_idx');
            $table->string('acquired_id')->nullable()->after('campaign_acquired')->index('sf_leads_acquired_id_idx');
            $table->string('content_acquired')->nullable()->after('acquired_id')->index('sf_leads_content_acq_idx');
            $table->string('vehicle_interest')->nullable()->after('content_acquired')->index('sf_leads_vehicle_interest_idx');
            $table->string('phone')->nullable()->after('vehicle_interest')->index('sf_leads_phone_idx');
            $table->string('mobile_phone')->nullable()->after('phone')->index('sf_leads_mobile_phone_idx');
            $table->string('email')->nullable()->after('mobile_phone')->index('sf_leads_email_idx');
            $table->boolean('is_converted')->default(false)->after('email')->index('sf_leads_converted_idx');
            $table->dateTime('converted_date')->nullable()->after('is_converted')->index('sf_leads_converted_date_idx');
            $table->string('converted_account_id')->nullable()->after('converted_date');
            $table->string('converted_contact_id')->nullable()->after('converted_account_id');
            $table->string('converted_opportunity_id')->nullable()->after('converted_contact_id')->index('sf_leads_converted_opp_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->dropIndex('sf_leads_campaign_acq_idx');
            $table->dropIndex('sf_leads_acquired_id_idx');
            $table->dropIndex('sf_leads_content_acq_idx');
            $table->dropIndex('sf_leads_vehicle_interest_idx');
            $table->dropIndex('sf_leads_phone_idx');
            $table->dropIndex('sf_leads_mobile_phone_idx');
            $table->dropIndex('sf_leads_email_idx');
            $table->dropIndex('sf_leads_converted_idx');
            $table->dropIndex('sf_leads_converted_date_idx');
            $table->dropIndex('sf_leads_converted_opp_idx');
            $table->dropColumn([
                'campaign_acquired',
                'acquired_id',
                'content_acquired',
                'vehicle_interest',
                'phone',
                'mobile_phone',
                'email',
                'is_converted',
                'converted_date',
                'converted_account_id',
                'converted_contact_id',
                'converted_opportunity_id',
            ]);
        });
    }
};
