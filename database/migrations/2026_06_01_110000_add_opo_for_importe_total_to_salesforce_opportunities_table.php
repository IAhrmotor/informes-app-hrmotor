<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_opportunities', 'opo_for_importe_total')) {
                $table->decimal('opo_for_importe_total', 14, 2)->nullable()->after('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (Schema::hasColumn('salesforce_opportunities', 'opo_for_importe_total')) {
                $table->dropColumn('opo_for_importe_total');
            }
        });
    }
};
