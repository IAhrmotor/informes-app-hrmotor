<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalyticalMetricRule extends Model
{
    protected $fillable = [
        'metric_key',
        'comparison_mode',
        'favorable_direction',
        'threshold_unit',
        'observation_threshold',
        'deviation_threshold',
        'critical_threshold',
        'minimum_baseline',
        'minimum_absolute_change',
    ];

    protected $casts = [
        'observation_threshold' => 'decimal:8',
        'deviation_threshold' => 'decimal:8',
        'critical_threshold' => 'decimal:8',
        'minimum_baseline' => 'decimal:8',
        'minimum_absolute_change' => 'decimal:8',
    ];

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(AnalyticalRuleSet::class, 'rule_set_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(AnalyticalMetricEvaluation::class, 'analytical_metric_rule_id');
    }
}
