<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_users', function (Blueprint $table) {
            $table->boolean('commission_appraiser')->default(false)->after('is_active')->index('sf_users_commission_appraiser_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_users', function (Blueprint $table) {
            $table->dropIndex('sf_users_commission_appraiser_idx');
            $table->dropColumn('commission_appraiser');
        });
    }
};
