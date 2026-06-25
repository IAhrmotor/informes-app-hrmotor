<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_opportunities', 'vehicle_purchase_date')) {
                $table->date('vehicle_purchase_date')->nullable()->after('vehicle_purchase_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (Schema::hasColumn('salesforce_opportunities', 'vehicle_purchase_date')) {
                $table->dropColumn('vehicle_purchase_date');
            }
        });
    }
};
