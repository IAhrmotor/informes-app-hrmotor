<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_users', function (Blueprint $table) {
            $table->string('user_delegation')->nullable()->after('profile_name')->index('sf_users_deleg_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_users', function (Blueprint $table) {
            $table->dropIndex('sf_users_deleg_idx');
            $table->dropColumn('user_delegation');
        });
    }
};
