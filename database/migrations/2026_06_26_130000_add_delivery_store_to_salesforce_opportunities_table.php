<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_opportunities', 'delivery_store')) {
                $table->string('delivery_store')->nullable()->after('owner_delegation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (Schema::hasColumn('salesforce_opportunities', 'delivery_store')) {
                $table->dropColumn('delivery_store');
            }
        });
    }
};
