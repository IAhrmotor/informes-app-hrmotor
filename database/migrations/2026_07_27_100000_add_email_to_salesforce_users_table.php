<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_users', 'email')) {
                $table->string('email')->nullable()->after('name')->index('sf_users_email_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_users', function (Blueprint $table): void {
            if (Schema::hasColumn('salesforce_users', 'email')) {
                $table->dropIndex('sf_users_email_idx');
                $table->dropColumn('email');
            }
        });
    }
};
