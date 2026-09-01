<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_delegation_manager_history', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 120)->unique('sf_deleg_mgr_source_uq');
            $table->string('delegation_salesforce_id', 18)->index('sf_deleg_mgr_deleg_id_idx');
            $table->string('delegation_name');
            $table->string('delegation_key')->index('sf_deleg_mgr_key_idx');
            $table->string('manager_salesforce_user_id', 18)->nullable()->index('sf_deleg_mgr_user_idx');
            $table->string('manager_name')->nullable();
            $table->timestamp('effective_at');
            $table->timestamp('coverage_from');
            $table->timestamp('coverage_to')->nullable();
            $table->timestamp('observed_at');
            $table->string('source', 32);
            $table->string('evidence_reference')->nullable();
            $table->string('recorded_by')->nullable();
            $table->boolean('history_verified')->default(false);
            $table->timestamps();
            $table->index(['delegation_key', 'effective_at'], 'sf_deleg_mgr_key_effective_idx');
            $table->index(['delegation_key', 'coverage_from', 'coverage_to'], 'sf_deleg_mgr_coverage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_delegation_manager_history');
    }
};
