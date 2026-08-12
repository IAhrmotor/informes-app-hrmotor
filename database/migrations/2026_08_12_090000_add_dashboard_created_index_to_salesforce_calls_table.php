<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_calls', function (Blueprint $table): void {
            $table->index(['included_in_dashboard', 'created_date'], 'sf_calls_dashboard_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_calls', function (Blueprint $table): void {
            $table->dropIndex('sf_calls_dashboard_created_idx');
        });
    }
};
