<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_commercial_report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->dateTime('period_start')->index('mcr_period_start_idx');
            $table->dateTime('period_end')->index('mcr_period_end_idx');
            $table->dateTime('previous_period_start')->nullable();
            $table->dateTime('previous_period_end')->nullable();
            $table->json('payload_json');
            $table->dateTime('generated_at')->index('mcr_generated_idx');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_commercial_report_snapshots');
    }
};
