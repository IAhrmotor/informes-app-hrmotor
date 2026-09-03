<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_opportunity_portal_reprocess_history', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_identifier');
            $table->unsignedBigInteger('opportunity_id');
            $table->string('opportunity_salesforce_id', 18);
            $table->string('reason', 500);
            $table->json('changed_fields');
            $table->json('previous_values');
            $table->json('new_values');
            $table->timestamp('recorded_at');

            $table->index('run_identifier', 'opp_portal_reprocess_run_idx');
            $table->index('opportunity_id', 'opp_portal_reprocess_local_idx');
            $table->index('opportunity_salesforce_id', 'opp_portal_reprocess_sf_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_opportunity_portal_reprocess_history');
    }
};
