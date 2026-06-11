<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            if (! Schema::hasColumn('campaign_lead_attributions', 'source_campaign_name')) {
                $table->string('source_campaign_name')->nullable()->after('platform')->index('campaign_lead_attr_source_campaign_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            if (Schema::hasColumn('campaign_lead_attributions', 'source_campaign_name')) {
                $table->dropIndex('campaign_lead_attr_source_campaign_idx');
                $table->dropColumn('source_campaign_name');
            }
        });
    }
};
