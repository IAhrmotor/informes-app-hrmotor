<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticalMetricEvaluation extends Model
{
    protected $fillable = [
        'analytical_metric_snapshot_id',
        'analytical_rule_set_id',
        'analytical_metric_rule_id',
        'module_key',
        'metric_key',
        'data_date',
        'evaluated_current_value',
        'evaluated_baseline_value',
        'evaluated_absolute_change',
        'evaluated_relative_change',
        'evaluated_snapshot_is_evaluable',
        'evaluated_snapshot_reason',
        'evaluated_snapshot_fingerprint',
        'status',
        'direction',
        'magnitude_band',
        'reason_code',
        'evaluated_at',
    ];

    protected $casts = [
        'data_date' => 'date',
        'evaluated_current_value' => 'decimal:8',
        'evaluated_baseline_value' => 'decimal:8',
        'evaluated_absolute_change' => 'decimal:8',
        'evaluated_relative_change' => 'decimal:8',
        'evaluated_snapshot_is_evaluable' => 'boolean',
        'evaluated_at' => 'datetime',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AnalyticalMetricSnapshot::class, 'analytical_metric_snapshot_id');
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(AnalyticalRuleSet::class, 'analytical_rule_set_id');
    }

    public function metricRule(): BelongsTo
    {
        return $this->belongsTo(AnalyticalMetricRule::class, 'analytical_metric_rule_id');
    }
}
