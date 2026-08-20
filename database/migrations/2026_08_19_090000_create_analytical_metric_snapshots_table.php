<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytical_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('module_key', 64);
            $table->string('metric_key', 128);
            $table->string('metric_label', 191);
            $table->string('source_key', 64);
            $table->string('source_identifier', 255);
            $table->char('source_identifier_hash', 64);
            $table->string('scope_key', 64);
            $table->string('value_format', 32);
            $table->date('data_date');
            $table->dateTime('source_cutoff_at');
            $table->decimal('current_value', 24, 8)->nullable();
            $table->decimal('d7_value', 24, 8)->nullable();
            $table->decimal('d14_value', 24, 8)->nullable();
            $table->decimal('d21_value', 24, 8)->nullable();
            $table->decimal('d28_value', 24, 8)->nullable();
            $table->unsignedTinyInteger('reference_count');
            $table->decimal('baseline_value', 24, 8)->nullable();
            $table->decimal('absolute_change', 24, 8)->nullable();
            $table->decimal('relative_change', 20, 8)->nullable();
            $table->decimal('d364_value', 24, 8)->nullable();
            $table->decimal('year_absolute_change', 24, 8)->nullable();
            $table->decimal('year_relative_change', 20, 8)->nullable();
            $table->boolean('is_evaluable');
            $table->string('evaluation_reason', 64)->nullable();
            $table->string('engine_version', 32);
            $table->dateTime('computed_at');
            $table->timestamps();

            $table->unique(
                ['module_key', 'metric_key', 'scope_key', 'source_identifier_hash', 'data_date'],
                'analytics_snap_identity_uq',
            );
            $table->index(['module_key', 'data_date'], 'analytics_snap_module_date_idx');
            $table->index(['module_key', 'metric_key', 'data_date'], 'analytics_snap_module_metric_date_idx');
            $table->index(['source_key', 'source_identifier_hash', 'data_date'], 'analytics_snap_source_hash_date_idx');
            $table->index(['is_evaluable', 'data_date'], 'analytics_snap_eval_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytical_metric_snapshots');
    }
};
