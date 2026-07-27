<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_financing_penalties')) {
            return;
        }

        Schema::table('commercial_financing_penalties', function (Blueprint $table): void {
            if (! Schema::hasColumn('commercial_financing_penalties', 'commercial_name')) {
                $table->string('commercial_name')->nullable()->after('commercial_email');
            }
        });

        Schema::table('commercial_financing_penalties', function (Blueprint $table): void {
            $table->string('commercial_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('commercial_financing_penalties')) {
            return;
        }

        Schema::table('commercial_financing_penalties', function (Blueprint $table): void {
            if (Schema::hasColumn('commercial_financing_penalties', 'commercial_name')) {
                $table->dropColumn('commercial_name');
            }
        });
    }
};
