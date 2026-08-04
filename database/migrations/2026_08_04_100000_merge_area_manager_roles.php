<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_users')) {
            return;
        }

        DB::table('report_users')
            ->where('role', 'area_manager_own_area')
            ->update(['role' => 'area_manager']);
    }

    public function down(): void
    {
        // Both roles are intentionally consolidated and cannot be separated reliably.
    }
};
