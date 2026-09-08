<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_opportunity_presence_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_identifier')->unique('sf_opp_presence_runs_identifier_uq');
            $table->string('reason', 500);
            $table->string('status')->index('sf_opp_presence_runs_status_idx');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedBigInteger('rows_examined')->default(0);
            $table->unsignedBigInteger('rows_changed')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_opportunity_presence_reconciliation_runs');
    }
};
