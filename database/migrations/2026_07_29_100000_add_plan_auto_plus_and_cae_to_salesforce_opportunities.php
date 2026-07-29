<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->decimal('plan_auto_plus_amount', 14, 2)->nullable()->after('contract_vehicle_sale_amount');
            $table->decimal('cae_amount', 14, 2)->nullable()->after('plan_auto_plus_amount');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->dropColumn(['plan_auto_plus_amount', 'cae_amount']);
        });
    }
};
