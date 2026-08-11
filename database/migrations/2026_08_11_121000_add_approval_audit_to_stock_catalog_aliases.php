<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_catalog_aliases', 'approval_status')) {
            Schema::table('stock_catalog_aliases', function (Blueprint $table): void {
                $table->string('approval_status', 32)->default('legacy_unverified')->index();
                $table->foreignId('approved_by_report_user_id')->nullable()->constrained('report_users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_catalog_aliases', 'approval_status')) {
            Schema::table('stock_catalog_aliases', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('approved_by_report_user_id');
                $table->dropColumn(['approval_status', 'approved_at']);
            });
        }
    }
};
