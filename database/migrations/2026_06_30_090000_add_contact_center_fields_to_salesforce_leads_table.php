<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->string('appointment_setter_id')->nullable()->after('owner_name')->index('sf_leads_appt_setter_id_idx');
            $table->string('appointment_setter_name')->nullable()->after('appointment_setter_id')->index('sf_leads_appt_setter_name_idx');
            $table->date('appointment_capture_date')->nullable()->after('fecha_asignacion')->index('sf_leads_appt_capture_idx');
            $table->boolean('appointment_call')->default(false)->after('appointment_capture_date')->index('sf_leads_appt_call_idx');
            $table->boolean('appointment_store')->default(false)->after('appointment_call')->index('sf_leads_appt_store_idx');
            $table->string('appointment_attended_status')->nullable()->after('appointment_store')->index('sf_leads_appt_attended_idx');
            $table->string('store_commercial_id')->nullable()->after('appointment_attended_status')->index('sf_leads_store_commercial_id_idx');
            $table->string('store_commercial_name')->nullable()->after('store_commercial_id');
            $table->string('candidate_status_formula')->nullable()->after('store_commercial_name')->index('sf_leads_candidate_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->dropIndex('sf_leads_appt_setter_id_idx');
            $table->dropIndex('sf_leads_appt_setter_name_idx');
            $table->dropIndex('sf_leads_appt_capture_idx');
            $table->dropIndex('sf_leads_appt_call_idx');
            $table->dropIndex('sf_leads_appt_store_idx');
            $table->dropIndex('sf_leads_appt_attended_idx');
            $table->dropIndex('sf_leads_store_commercial_id_idx');
            $table->dropIndex('sf_leads_candidate_status_idx');
            $table->dropColumn([
                'appointment_setter_id',
                'appointment_setter_name',
                'appointment_capture_date',
                'appointment_call',
                'appointment_store',
                'appointment_attended_status',
                'store_commercial_id',
                'store_commercial_name',
                'candidate_status_formula',
            ]);
        });
    }
};
