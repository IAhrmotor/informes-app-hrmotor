<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->string('record_type_name')->nullable()->after('status')->index('sf_leads_record_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->dropIndex('sf_leads_record_type_idx');
            $table->dropColumn('record_type_name');
        });
    }
};
