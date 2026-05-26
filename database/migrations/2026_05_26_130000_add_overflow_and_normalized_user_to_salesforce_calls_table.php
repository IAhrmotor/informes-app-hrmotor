<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_calls', function (Blueprint $table) {
            $table->string('normalized_user_key')->nullable()->after('operational_user_name')->index('sf_calls_normalized_user_key_idx');
            $table->boolean('is_overflow')->default(false)->after('is_lost')->index('sf_calls_overflow_idx');
            $table->string('overflow_reason')->nullable()->after('is_overflow');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_calls', function (Blueprint $table) {
            $table->dropIndex('sf_calls_normalized_user_key_idx');
            $table->dropIndex('sf_calls_overflow_idx');
            $table->dropColumn([
                'normalized_user_key',
                'is_overflow',
                'overflow_reason',
            ]);
        });
    }
};
