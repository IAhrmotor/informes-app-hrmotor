<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_financing_penalty_imports')) {
            Schema::create('commercial_financing_penalty_imports', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('uploaded_by_report_user_id')->nullable();
                $table->string('original_filename');
                $table->string('stored_path')->nullable();
                $table->unsignedInteger('rows_read')->default(0);
                $table->unsignedInteger('rows_imported')->default(0);
                $table->unsignedInteger('rows_unmatched')->default(0);
                $table->json('commission_months')->nullable();
                $table->timestamps();
            });
        }

        // MySQL limits identifiers to 64 characters. The default Laravel name
        // for this table and column exceeds that limit, so keep it explicit.
        Schema::table('commercial_financing_penalty_imports', function (Blueprint $table): void {
            $table->foreign('uploaded_by_report_user_id', 'cfpi_uploaded_user_fk')
                ->references('id')
                ->on('report_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_financing_penalty_imports');
    }
};
