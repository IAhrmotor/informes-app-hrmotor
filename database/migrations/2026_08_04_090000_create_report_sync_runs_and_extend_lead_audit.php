<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('dataset')->index();
            $table->string('source')->index();
            $table->string('status', 20)->index();
            $table->dateTime('period_start_at')->nullable();
            $table->dateTime('period_end_at')->nullable();
            $table->dateTime('source_cutoff_at')->nullable()->index();
            $table->dateTime('started_at')->index();
            $table->dateTime('completed_at')->nullable()->index();
            $table->string('timezone')->default('Europe/Madrid');
            $table->json('stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['dataset', 'source', 'status', 'completed_at'], 'report_sync_runs_lookup_idx');
        });

        Schema::table('salesforce_leads', function (Blueprint $table): void {
            $table->string('salesforce_master_record_id')->nullable()->after('salesforce_deleted_at')->index('sf_leads_master_record_idx');
            $table->string('sync_metadata_source')->nullable()->after('deletion_detection_source');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table): void {
            $table->dropIndex('sf_leads_master_record_idx');
            $table->dropColumn(['salesforce_master_record_id', 'sync_metadata_source']);
        });

        Schema::dropIfExists('report_sync_runs');
    }
};
