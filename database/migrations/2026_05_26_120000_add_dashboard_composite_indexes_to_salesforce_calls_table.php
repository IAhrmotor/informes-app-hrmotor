<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_calls', function (Blueprint $table) {
            $table->index(['created_date', 'call_origin', 'call_status', 'direction'], 'sf_calls_created_origin_status_dir_idx');
            $table->index(['created_date', 'operational_team'], 'sf_calls_created_team_idx');
            $table->index(['created_date', 'delegation', 'zone'], 'sf_calls_created_delegation_zone_idx');
            $table->index(['created_date', 'portal_resolved'], 'sf_calls_created_portal_idx');
            $table->index(['created_date', 'operational_user_id'], 'sf_calls_created_user_id_idx');
            $table->index(['created_date', 'operational_user_name'], 'sf_calls_created_user_name_idx');
            $table->index(['created_date', 'owner_id'], 'sf_calls_created_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_calls', function (Blueprint $table) {
            $table->dropIndex('sf_calls_created_origin_status_dir_idx');
            $table->dropIndex('sf_calls_created_team_idx');
            $table->dropIndex('sf_calls_created_delegation_zone_idx');
            $table->dropIndex('sf_calls_created_portal_idx');
            $table->dropIndex('sf_calls_created_user_id_idx');
            $table->dropIndex('sf_calls_created_user_name_idx');
            $table->dropIndex('sf_calls_created_owner_idx');
        });
    }
};
