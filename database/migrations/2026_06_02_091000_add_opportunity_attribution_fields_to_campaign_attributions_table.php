<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_attributions', function (Blueprint $table): void {
            if (! Schema::hasColumn('campaign_attributions', 'opportunity_attribution_method')) {
                $table->string('opportunity_attribution_method')->nullable()->after('attribution_confidence')->index('camp_attr_opp_attr_method_idx');
            }

            if (! Schema::hasColumn('campaign_attributions', 'opportunity_attribution_confidence')) {
                $table->string('opportunity_attribution_confidence')->nullable()->after('opportunity_attribution_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaign_attributions', function (Blueprint $table): void {
            if (Schema::hasColumn('campaign_attributions', 'opportunity_attribution_method')) {
                $table->dropIndex('camp_attr_opp_attr_method_idx');
                $table->dropColumn('opportunity_attribution_method');
            }

            if (Schema::hasColumn('campaign_attributions', 'opportunity_attribution_confidence')) {
                $table->dropColumn('opportunity_attribution_confidence');
            }
        });
    }
};
