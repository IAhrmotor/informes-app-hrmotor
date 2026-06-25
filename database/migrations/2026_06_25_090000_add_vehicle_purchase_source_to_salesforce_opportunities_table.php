<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_opportunities', 'vehicle_purchase_source')) {
                $table->string('vehicle_purchase_source')->nullable()->after('vehicle_purchase_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (Schema::hasColumn('salesforce_opportunities', 'vehicle_purchase_source')) {
                $table->dropColumn('vehicle_purchase_source');
            }
        });
    }
};
