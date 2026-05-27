<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('period_start')->index('camp_snap_period_start_idx');
            $table->date('period_end')->index('camp_snap_period_end_idx');
            $table->integer('attribution_window_days')->default(30);
            $table->string('filters_hash')->nullable()->index('camp_snap_filters_hash_idx');
            $table->json('summary');
            $table->json('campaigns');
            $table->json('rankings');
            $table->json('warnings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_report_snapshots');
    }
};
