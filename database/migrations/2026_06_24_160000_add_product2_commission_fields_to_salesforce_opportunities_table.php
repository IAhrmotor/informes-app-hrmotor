<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_opportunities', 'vehicle_sale_price')) {
                $table->decimal('vehicle_sale_price', 14, 2)->nullable()->after('vehicle_interest_id');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'vehicle_purchase_price')) {
                $table->decimal('vehicle_purchase_price', 14, 2)->nullable()->after('vehicle_sale_price');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'vehicle_buyer_id')) {
                $table->string('vehicle_buyer_id')->nullable()->index('sf_opps_vehicle_buyer_idx')->after('vehicle_purchase_price');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'vehicle_buyer_name')) {
                $table->string('vehicle_buyer_name')->nullable()->after('vehicle_buyer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            try {
                $table->dropIndex('sf_opps_vehicle_buyer_idx');
            } catch (Throwable) {
            }

            foreach ([
                'vehicle_buyer_name',
                'vehicle_buyer_id',
                'vehicle_purchase_price',
                'vehicle_sale_price',
            ] as $column) {
                if (Schema::hasColumn('salesforce_opportunities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
