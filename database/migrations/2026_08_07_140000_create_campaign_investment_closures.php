<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_investment_closures', function (Blueprint $table): void {
            $table->id();
            $table->date('month')->unique();
            $table->string('status')->default('open')->index();
            $table->unsignedInteger('snapshot_version')->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('report_users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('report_users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_investment_closure_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closure_id')->constrained('campaign_investment_closures')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('economic_rows');
            $table->timestamp('source_cutoff_at');
            $table->string('rule_version');
            $table->timestamps();
            $table->unique(['closure_id', 'version']);
        });

        Schema::create('campaign_investment_closure_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closure_id')->constrained('campaign_investment_closures')->restrictOnDelete();
            $table->string('event');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->nullable()->constrained('report_users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_investment_closure_events');
        Schema::dropIfExists('campaign_investment_closure_snapshots');
        Schema::dropIfExists('campaign_investment_closures');
    }
};
