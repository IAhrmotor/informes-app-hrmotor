<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytical_rule_sets', function (Blueprint $table): void {
            $table->id();
            $table->string('module_key', 64);
            $table->unsignedInteger('version_number');
            $table->string('version_key', 64);
            $table->string('status', 32);
            $table->string('change_reason', 500)->nullable();
            $table->unsignedBigInteger('created_by_report_user_id')->nullable();
            $table->dateTime('activated_at');
            $table->timestamps();

            $table->unique(['module_key', 'version_number'], 'analytics_rules_module_version_uq');
            $table->unique(['module_key', 'version_key'], 'analytics_rules_module_key_uq');
            $table->index(['module_key', 'status', 'activated_at'], 'analytics_rules_active_idx');
        });

        Schema::create('analytical_metric_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rule_set_id');
            $table->string('metric_key', 128);
            $table->string('comparison_mode', 64);
            $table->string('favorable_direction', 32);
            $table->string('threshold_unit', 64);
            $table->decimal('observation_threshold', 20, 8);
            $table->decimal('deviation_threshold', 20, 8);
            $table->decimal('critical_threshold', 20, 8);
            $table->decimal('minimum_baseline', 24, 8)->nullable();
            $table->decimal('minimum_absolute_change', 24, 8)->nullable();
            $table->timestamps();

            $table->unique(['rule_set_id', 'metric_key'], 'analytics_metric_rules_identity_uq');
            $table->foreign('rule_set_id', 'analytics_metric_rules_set_fk')
                ->references('id')->on('analytical_rule_sets')->cascadeOnDelete();
        });

        Schema::create('analytical_metric_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('analytical_metric_snapshot_id');
            $table->unsignedBigInteger('analytical_rule_set_id');
            $table->unsignedBigInteger('analytical_metric_rule_id');
            $table->string('module_key', 64);
            $table->string('metric_key', 128);
            $table->date('data_date');
            $table->decimal('evaluated_current_value', 24, 8)->nullable();
            $table->decimal('evaluated_baseline_value', 24, 8)->nullable();
            $table->decimal('evaluated_absolute_change', 24, 8)->nullable();
            $table->decimal('evaluated_relative_change', 20, 8)->nullable();
            $table->boolean('evaluated_snapshot_is_evaluable');
            $table->string('evaluated_snapshot_reason', 64)->nullable();
            $table->char('evaluated_snapshot_fingerprint', 64);
            $table->string('status', 32);
            $table->string('direction', 32);
            $table->string('magnitude_band', 32);
            $table->string('reason_code', 64);
            $table->dateTime('evaluated_at');
            $table->timestamps();

            $table->unique(
                ['analytical_metric_snapshot_id', 'analytical_rule_set_id'],
                'analytics_evaluation_snapshot_rules_uq',
            );
            $table->index(['module_key', 'data_date'], 'analytics_evaluation_module_date_idx');
            $table->index(['module_key', 'metric_key', 'data_date'], 'analytics_evaluation_metric_date_idx');
            $table->index(['module_key', 'status', 'data_date'], 'analytics_evaluation_status_date_idx');
            $table->index(['status', 'data_date'], 'analytics_evaluation_global_status_idx');
            $table->foreign('analytical_metric_snapshot_id', 'analytics_evaluation_snapshot_fk')
                ->references('id')->on('analytical_metric_snapshots')->cascadeOnDelete();
            $table->foreign('analytical_rule_set_id', 'analytics_evaluation_rule_set_fk')
                ->references('id')->on('analytical_rule_sets')->cascadeOnDelete();
            $table->foreign('analytical_metric_rule_id', 'analytics_evaluation_metric_rule_fk')
                ->references('id')->on('analytical_metric_rules')->cascadeOnDelete();
        });

        $now = now();
        $ruleSetId = DB::table('analytical_rule_sets')->insertGetId([
            'module_key' => 'seo',
            'version_number' => 1,
            'version_key' => 'seo_rules_v1',
            'status' => 'active',
            'change_reason' => null,
            'created_by_report_user_id' => null,
            'activated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('analytical_metric_rules')->insert(array_map(
            static fn (array $rule): array => $rule + [
                'rule_set_id' => $ruleSetId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                self::volumeRule('search_console_clicks', '50', '10'),
                self::volumeRule('search_console_impressions', '1000', '100'),
                self::volumeRule('salesforce_organic_leads', '5', '2'),
                self::volumeRule('ga4_organic_key_events', '10', '3'),
                self::absoluteRule('search_console_ctr', 'absolute_percentage_points', 'increase', 'percentage_points'),
                self::absoluteRule('search_console_position', 'absolute_value', 'decrease', 'positions'),
            ],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('analytical_metric_evaluations');
        Schema::dropIfExists('analytical_metric_rules');
        Schema::dropIfExists('analytical_rule_sets');
    }

    /** @return array<string, mixed> */
    private static function volumeRule(string $metric, string $minimumBaseline, string $minimumChange): array
    {
        return [
            'metric_key' => $metric,
            'comparison_mode' => 'relative_percent',
            'favorable_direction' => 'increase',
            'threshold_unit' => 'percent',
            'observation_threshold' => '10.00000000',
            'deviation_threshold' => '20.00000000',
            'critical_threshold' => '35.00000000',
            'minimum_baseline' => $minimumBaseline,
            'minimum_absolute_change' => $minimumChange,
        ];
    }

    /** @return array<string, mixed> */
    private static function absoluteRule(string $metric, string $mode, string $direction, string $unit): array
    {
        return [
            'metric_key' => $metric,
            'comparison_mode' => $mode,
            'favorable_direction' => $direction,
            'threshold_unit' => $unit,
            'observation_threshold' => '0.50000000',
            'deviation_threshold' => '1.00000000',
            'critical_threshold' => '2.00000000',
            'minimum_baseline' => null,
            'minimum_absolute_change' => null,
        ];
    }
};
