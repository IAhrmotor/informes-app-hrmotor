<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_financing_penalties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('commercial_financing_penalty_imports')->cascadeOnDelete();
            $table->date('commission_month')->index();
            $table->string('commercial_email')->index();
            $table->string('salesforce_user_id')->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->string('source_sheet', 191)->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->json('raw_values')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->index(['commission_month', 'commercial_email', 'is_active'], 'fin_penalties_month_email_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_financing_penalties');
    }
};
