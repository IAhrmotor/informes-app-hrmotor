<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_calls', function (Blueprint $table) {
            $table->string('poll_value')->nullable()->after('portal_resolution_source')->index('sf_calls_poll_value_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_calls', function (Blueprint $table) {
            $table->dropIndex('sf_calls_poll_value_idx');
            $table->dropColumn('poll_value');
        });
    }
};
