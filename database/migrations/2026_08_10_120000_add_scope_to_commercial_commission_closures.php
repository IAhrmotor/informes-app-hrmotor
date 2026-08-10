<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_commission_closures', function (Blueprint $table): void {
            $table->string('closure_scope', 32)->default('legacy')->after('month')->index();
        });

        Schema::table('commercial_commission_closures', function (Blueprint $table): void {
            $table->dropUnique(['month']);
            $table->unique(['month', 'closure_scope'], 'commission_closure_month_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_commission_closures', function (Blueprint $table): void {
            $table->dropUnique('commission_closure_month_scope_unique');
            $table->dropIndex(['closure_scope']);
            $table->dropColumn('closure_scope');
            $table->unique('month');
        });
    }
};
