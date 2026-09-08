<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table) {
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('salesforce_deleted_at')->nullable();
            $table->string('deletion_detection_source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table) {
            $table->dropColumn([
                'is_deleted',
                'salesforce_deleted_at',
                'deletion_detection_source',
            ]);
        });
    }
};
