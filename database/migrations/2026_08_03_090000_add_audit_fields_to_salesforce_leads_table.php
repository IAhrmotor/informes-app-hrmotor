<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salesforce_leads', 'record_type_normalized')) {
            Schema::table('salesforce_leads', function (Blueprint $table) {
                $table->string('record_type_normalized')->nullable()->after('record_type_name')->index('sf_leads_record_type_norm_idx');
                $table->string('resolved_channel')->nullable()->after('portal_text')->index('sf_leads_channel_resolved_idx');
                $table->string('resolved_portal')->nullable()->after('resolved_channel')->index('sf_leads_portal_resolved_idx');
                $table->string('portal_resolution_source')->nullable()->after('resolved_portal');
                $table->dateTime('salesforce_last_modified_at')->nullable()->after('last_activity_date')->index('sf_leads_last_modified_idx');
                $table->timestamp('synced_at')->nullable()->after('salesforce_last_modified_at')->index('sf_leads_synced_at_idx');
                $table->boolean('is_deleted')->default(false)->after('synced_at')->index('sf_leads_deleted_idx');
                $table->dateTime('salesforce_deleted_at')->nullable()->after('is_deleted');
            });
        }
    }

    public function down(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->dropIndex('sf_leads_record_type_norm_idx');
            $table->dropIndex('sf_leads_channel_resolved_idx');
            $table->dropIndex('sf_leads_portal_resolved_idx');
            $table->dropIndex('sf_leads_last_modified_idx');
            $table->dropIndex('sf_leads_synced_at_idx');
            $table->dropIndex('sf_leads_deleted_idx');
            $table->dropColumn([
                'record_type_normalized',
                'resolved_channel',
                'resolved_portal',
                'portal_resolution_source',
                'salesforce_last_modified_at',
                'synced_at',
                'is_deleted',
                'salesforce_deleted_at',
            ]);
        });
    }
};
