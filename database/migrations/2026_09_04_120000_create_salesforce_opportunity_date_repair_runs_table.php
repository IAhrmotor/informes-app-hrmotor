<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_opportunity_date_repair_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_identifier')->unique();
            $table->string('reason', 500);
            $table->string('status', 20)->index();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('rows_examined')->default(0);
            $table->unsignedInteger('rows_changed')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_opportunity_date_repair_runs');
    }
};
