<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_opportunities', 'gestion_de_venta')) {
                $table->boolean('gestion_de_venta')->nullable()->index('sf_opps_sale_management_idx')->after('importe_financiado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            try {
                $table->dropIndex('sf_opps_sale_management_idx');
            } catch (Throwable) {
            }

            if (Schema::hasColumn('salesforce_opportunities', 'gestion_de_venta')) {
                $table->dropColumn('gestion_de_venta');
            }
        });
    }
};
