<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_performance_monthly_targets', function (Blueprint $table): void {
            $table->id();
            $table->date('month')->unique();
            $table->unsignedInteger('reservations_target');
            $table->boolean('is_explicit')->default(false);
            $table->foreignId('updated_by_report_user_id')->nullable();
            $table->timestamps();

            $table->foreign('updated_by_report_user_id', 'commercial_perf_target_updated_user_fk')
                ->references('id')
                ->on('report_users')
                ->nullOnDelete();
        });

        Schema::create('commercial_delegation_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('salesforce_user_id');
            $table->string('delegation')->nullable();
            $table->string('zone')->nullable();
            $table->dateTime('observed_from');
            $table->dateTime('observed_until')->nullable();
            $table->unsignedTinyInteger('open_marker')->nullable()->default(1);
            $table->string('source', 40)->default('salesforce_user_observation');
            $table->timestamps();

            $table->unique(['salesforce_user_id', 'observed_from'], 'commercial_deleg_snapshot_user_from_uq');
            $table->unique(['salesforce_user_id', 'open_marker'], 'commercial_deleg_snapshot_single_open_uq');
            $table->index(['salesforce_user_id', 'observed_until'], 'commercial_deleg_snapshot_user_until_idx');
            $table->index(['delegation', 'observed_from'], 'commercial_deleg_snapshot_deleg_from_idx');
        });

        Schema::create('salesforce_opportunity_stage_transitions', function (Blueprint $table): void {
            $table->id();
            $table->string('salesforce_history_id');
            $table->string('opportunity_salesforce_id');
            $table->string('previous_stage')->nullable();
            $table->string('new_stage');
            $table->dateTime('transitioned_at');
            $table->date('reservation_date')->nullable();
            $table->string('owner_id')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('source', 40)->default('OpportunityHistory');
            $table->boolean('is_reservation_cancellation')->default(false);
            $table->string('quality_status', 48)->default('reservation_not_demonstrated');
            $table->dateTime('synced_at');
            $table->timestamps();

            $table->unique('salesforce_history_id', 'sf_opp_stage_history_uq');
            $table->index(['transitioned_at', 'new_stage'], 'sf_opp_stage_transition_date_stage_idx');
            $table->index(['opportunity_salesforce_id', 'transitioned_at'], 'sf_opp_stage_transition_opp_date_idx');
            $table->index(['owner_id', 'transitioned_at'], 'sf_opp_stage_transition_owner_date_idx');
            $table->index(['is_reservation_cancellation', 'transitioned_at'], 'sf_opp_stage_transition_valid_date_idx');
        });

        Schema::create('salesforce_opportunity_history_sync_intervals', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('range_start');
            $table->dateTime('range_end');
            $table->dateTime('completed_at');
            $table->string('source', 40)->default('OpportunityHistory');
            $table->unsignedInteger('queried_rows')->default(0);
            $table->unsignedInteger('unresolved_dependencies')->default(0);
            $table->boolean('is_kpi_certified')->default(true);
            $table->timestamps();

            $table->unique(['range_start', 'range_end', 'source'], 'sf_opp_history_interval_range_uq');
            $table->index(['range_start', 'range_end'], 'sf_opp_history_interval_coverage_idx');
        });

        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->dateTime('salesforce_last_modified_at')
                ->nullable()
                ->after('created_date')
                ->index('sf_opps_last_modified_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->dropIndex('sf_opps_last_modified_idx');
            $table->dropColumn('salesforce_last_modified_at');
        });

        Schema::dropIfExists('salesforce_opportunity_history_sync_intervals');
        Schema::dropIfExists('salesforce_opportunity_stage_transitions');
        Schema::dropIfExists('commercial_delegation_snapshots');
        Schema::dropIfExists('commercial_performance_monthly_targets');
    }
};
