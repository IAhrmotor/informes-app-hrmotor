<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salesforce_vehicles', 'registration_date')) {
            Schema::table('salesforce_vehicles', function (Blueprint $table): void {
                $table->date('registration_date')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('salesforce_vehicles', 'registration_date')) {
            Schema::table('salesforce_vehicles', function (Blueprint $table): void {
                $table->dropColumn('registration_date');
            });
        }
    }
};
