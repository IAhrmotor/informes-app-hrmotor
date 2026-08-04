<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_users') || Schema::hasColumn('report_users', 'area_zone')) {
            return;
        }

        Schema::table('report_users', function (Blueprint $table): void {
            $table->string('area_zone', 32)->nullable()->after('role')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('report_users') || ! Schema::hasColumn('report_users', 'area_zone')) {
            return;
        }

        Schema::table('report_users', function (Blueprint $table): void {
            $table->dropIndex(['area_zone']);
            $table->dropColumn('area_zone');
        });
    }
};
