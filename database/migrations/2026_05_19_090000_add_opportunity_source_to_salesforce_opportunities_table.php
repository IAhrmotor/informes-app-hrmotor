<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table) {
            $table->string('opportunity_source_raw')->nullable()->after('portal_original')->index('sf_opps_source_raw_idx');
            $table->string('opportunity_source_normalized')->nullable()->after('opportunity_source_raw')->index('sf_opps_source_norm_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table) {
            $table->dropIndex('sf_opps_source_raw_idx');
            $table->dropIndex('sf_opps_source_norm_idx');
            $table->dropColumn(['opportunity_source_raw', 'opportunity_source_normalized']);
        });
    }
};
