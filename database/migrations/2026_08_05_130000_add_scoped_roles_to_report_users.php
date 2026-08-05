<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_users', function (Blueprint $table): void {
            $table->foreignId('master_delegation_id')->nullable()->after('area_zone')->constrained('master_delegations')->nullOnDelete();
            $table->string('salesforce_user_id')->nullable()->index()->after('master_delegation_id');
        });
    }

    public function down(): void
    {
        Schema::table('report_users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('master_delegation_id');
            $table->dropColumn('salesforce_user_id');
        });
    }
};
