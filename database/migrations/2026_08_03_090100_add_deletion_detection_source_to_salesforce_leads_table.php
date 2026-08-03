<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->string('deletion_detection_source')->nullable()->after('salesforce_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->dropColumn('deletion_detection_source');
        });
    }
};
