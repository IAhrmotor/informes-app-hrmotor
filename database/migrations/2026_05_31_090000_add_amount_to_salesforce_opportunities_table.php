<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salesforce_opportunities', 'amount')) {
            Schema::table('salesforce_opportunities', function (Blueprint $table): void {
                $table->decimal('amount', 14, 2)->nullable()->after('close_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('salesforce_opportunities', 'amount')) {
            Schema::table('salesforce_opportunities', function (Blueprint $table): void {
                $table->dropColumn('amount');
            });
        }
    }
};
