<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salesforce_sale_snapshots', 'selected_opportunity_salesforce_id')) {
            Schema::table('salesforce_sale_snapshots', function (Blueprint $table): void {
                $table->string('selected_opportunity_salesforce_id')->nullable()->after('invalid_reason');
            });
        }
        if (! Schema::hasColumn('salesforce_sale_snapshots', 'duplicate_resolution_reason')) {
            Schema::table('salesforce_sale_snapshots', function (Blueprint $table): void {
                $table->string('duplicate_resolution_reason')->nullable()->after('selected_opportunity_salesforce_id');
            });
        }
        $indexNames = collect(Schema::getIndexes('salesforce_sale_snapshots'))->pluck('name');
        if (! $indexNames->contains('sale_snapshot_selected_opp_idx')) {
            Schema::table('salesforce_sale_snapshots', function (Blueprint $table): void {
                $table->index('selected_opportunity_salesforce_id', 'sale_snapshot_selected_opp_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('salesforce_sale_snapshots', function (Blueprint $table): void {
            $table->dropIndex('sale_snapshot_selected_opp_idx');
            $table->dropColumn(['selected_opportunity_salesforce_id', 'duplicate_resolution_reason']);
        });
    }
};
