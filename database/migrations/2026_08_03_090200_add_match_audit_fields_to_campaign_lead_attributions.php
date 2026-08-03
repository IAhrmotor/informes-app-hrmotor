<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            $table->string('attribution_method')->nullable()->after('content_acquired');
            $table->string('attribution_confidence')->nullable()->after('attribution_method');
            $table->string('match_status')->nullable()->after('attribution_confidence');
            $table->string('campaign_source_type')->nullable()->after('match_status');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            $table->dropColumn([
                'attribution_method',
                'attribution_confidence',
                'match_status',
                'campaign_source_type',
            ]);
        });
    }
};
