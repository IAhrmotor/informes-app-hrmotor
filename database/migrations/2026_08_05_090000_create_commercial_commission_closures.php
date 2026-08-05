<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_commission_closures', function (Blueprint $table): void {
            $table->id();
            $table->string('month', 7)->unique();
            $table->string('status', 32)->default('provisional')->index();
            $table->json('component_statuses')->nullable();
            $table->json('issues')->nullable();
            $table->timestamp('data_cutoff_at')->nullable();
            $table->string('formula_version')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('report_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('report_users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->unsignedInteger('snapshot_version')->default(0);
            $table->timestamps();
        });

        Schema::create('commercial_commission_closure_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closure_id')->constrained('commercial_commission_closures')->cascadeOnDelete();
            $table->string('action', 32)->index();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('report_user_id')->nullable()->constrained('report_users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('commercial_commission_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closure_id')->constrained('commercial_commission_closures')->cascadeOnDelete();
            $table->string('month', 7)->index();
            $table->unsignedInteger('version');
            $table->string('formula_version');
            $table->timestamp('data_cutoff_at');
            $table->longText('payload');
            $table->json('source_state')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('report_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['closure_id', 'version'], 'commission_snapshot_closure_version_unique');
        });

        Schema::create('commercial_commission_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_id')->index();
            $table->string('original_month', 7)->index();
            $table->string('application_month', 7)->index();
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->string('status', 32)->default('pending')->index();
            $table->foreignId('created_by')->nullable()->constrained('report_users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->json('source_context')->nullable();
            $table->timestamps();

            $table->index(['original_month', 'status'], 'commission_adjustment_original_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_commission_adjustments');
        Schema::dropIfExists('commercial_commission_snapshots');
        Schema::dropIfExists('commercial_commission_closure_events');
        Schema::dropIfExists('commercial_commission_closures');
    }
};
