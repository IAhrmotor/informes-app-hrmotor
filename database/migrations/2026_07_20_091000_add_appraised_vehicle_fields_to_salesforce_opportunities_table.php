<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table) {
            $table->string('appraised_vehicle_id')->nullable()->after('vehicle_interest_id')->index('sf_opps_appraised_vehicle_idx');
            $table->string('appraised_vehicle_plate')->nullable()->after('appraised_vehicle_id')->index('sf_opps_appraised_plate_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table) {
            $table->dropIndex('sf_opps_appraised_vehicle_idx');
            $table->dropIndex('sf_opps_appraised_plate_idx');
            $table->dropColumn(['appraised_vehicle_id', 'appraised_vehicle_plate']);
        });
    }
};
