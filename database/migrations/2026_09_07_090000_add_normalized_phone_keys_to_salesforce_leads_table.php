<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->string('phone_normalized', 255)
                ->nullable()
                ->after('phone')
                ->index('sf_leads_phone_norm_idx');
            $table->string('mobile_phone_normalized', 255)
                ->nullable()
                ->after('mobile_phone')
                ->index('sf_leads_mobile_norm_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->dropIndex('sf_leads_phone_norm_idx');
            $table->dropIndex('sf_leads_mobile_norm_idx');
            $table->dropColumn(['phone_normalized', 'mobile_phone_normalized']);
        });
    }
};
