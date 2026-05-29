<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('campaign_attributions', 'match_status')) {
            Schema::table('campaign_attributions', function (Blueprint $table) {
                $table->string('match_status')->nullable()->after('attribution_confidence')->index('camp_attr_match_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('campaign_attributions', 'match_status')) {
            Schema::table('campaign_attributions', function (Blueprint $table) {
                $table->dropIndex('camp_attr_match_status_idx');
                $table->dropColumn('match_status');
            });
        }
    }
};
