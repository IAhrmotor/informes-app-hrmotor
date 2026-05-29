<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('campaign_attributions', 'campaign_source_type')) {
            Schema::table('campaign_attributions', function (Blueprint $table): void {
                $table->string('campaign_source_type')
                    ->nullable()
                    ->after('match_status')
                    ->index('camp_attr_source_type_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('campaign_attributions', 'campaign_source_type')) {
            Schema::table('campaign_attributions', function (Blueprint $table): void {
                $table->dropIndex('camp_attr_source_type_idx');
                $table->dropColumn('campaign_source_type');
            });
        }
    }
};
