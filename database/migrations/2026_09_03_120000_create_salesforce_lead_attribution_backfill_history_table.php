<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_lead_attribution_backfill_history', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_identifier');
            $table->string('source_table', 64);
            $table->string('salesforce_id', 18);
            $table->string('reason', 500);
            $table->json('changed_fields');
            $table->json('previous_values');
            $table->json('new_values');
            $table->timestamp('recorded_at');

            $table->index(['run_identifier', 'source_table'], 'lead_attr_backfill_run_table_idx');
            $table->index(['source_table', 'salesforce_id'], 'lead_attr_backfill_table_lead_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_lead_attribution_backfill_history');
    }
};
