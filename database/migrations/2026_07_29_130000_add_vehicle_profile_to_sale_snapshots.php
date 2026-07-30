<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_sale_snapshots', function (Blueprint $table): void {
            $table->string('vehicle_brand')->nullable()->index()->after('vehicle_plate');
            $table->string('vehicle_model')->nullable()->index()->after('vehicle_brand');
            $table->string('vehicle_segment')->nullable()->index()->after('vehicle_model');
            $table->string('vehicle_fuel')->nullable()->index()->after('vehicle_segment');
            $table->string('vehicle_body')->nullable()->index()->after('vehicle_fuel');
            $table->unsignedInteger('vehicle_mileage')->nullable()->after('vehicle_body');
            $table->string('vehicle_purchase_source')->nullable()->index()->after('vehicle_mileage');
            $table->string('vehicle_buyer_name')->nullable()->index()->after('vehicle_purchase_source');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_sale_snapshots', function (Blueprint $table): void {
            $table->dropColumn([
                'vehicle_brand',
                'vehicle_model',
                'vehicle_segment',
                'vehicle_fuel',
                'vehicle_body',
                'vehicle_mileage',
                'vehicle_purchase_source',
                'vehicle_buyer_name',
            ]);
        });
    }
};
