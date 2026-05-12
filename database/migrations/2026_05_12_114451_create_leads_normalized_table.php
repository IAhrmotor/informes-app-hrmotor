<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads_normalized', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_raw_id')
                ->constrained('leads_raw')
                ->cascadeOnDelete();

            $table->timestamp('lead_created_at')->nullable();

            $table->string('channel_direction');
            $table->string('portal_original')->nullable();
            $table->string('portal_group')->nullable();

            $table->string('delegation_name')->nullable();
            $table->string('commercial_group')->nullable();
            $table->string('commercial_name')->nullable();

            $table->boolean('is_exposition')->default(false);
            $table->boolean('is_converted')->default(false);
            $table->boolean('is_discarded')->default(false);
            $table->boolean('is_potential')->default(false);

            $table->boolean('has_task_event')->default(false);
            $table->boolean('has_recent_follow_up')->default(false);

            $table->unsignedInteger('minutes_to_assignment')->nullable();
            $table->unsignedInteger('minutes_to_first_task_event')->nullable();

            $table->string('data_quality_status')->default('ok');
            $table->string('data_quality_issue')->nullable();

            $table->timestamps();

            $table->index('lead_created_at');
            $table->index('channel_direction');
            $table->index('portal_original');
            $table->index('portal_group');
            $table->index('delegation_name');
            $table->index('commercial_group');
            $table->index('commercial_name');
            $table->index('is_exposition');
            $table->index('is_converted');
            $table->index('is_discarded');
            $table->index('data_quality_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads_normalized');
    }
};