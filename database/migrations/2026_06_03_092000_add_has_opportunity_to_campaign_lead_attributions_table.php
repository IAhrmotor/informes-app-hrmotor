<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            if (! Schema::hasColumn('campaign_lead_attributions', 'has_opportunity')) {
                $table->boolean('has_opportunity')->default(false)->after('opportunity_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            if (Schema::hasColumn('campaign_lead_attributions', 'has_opportunity')) {
                $table->dropColumn('has_opportunity');
            }
        });
    }
};
