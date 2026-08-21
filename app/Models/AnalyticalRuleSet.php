<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalyticalRuleSet extends Model
{
    protected $fillable = [
        'module_key',
        'version_number',
        'version_key',
        'status',
        'change_reason',
        'created_by_report_user_id',
        'activated_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'created_by_report_user_id' => 'integer',
        'activated_at' => 'datetime',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(AnalyticalMetricRule::class, 'rule_set_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(AnalyticalMetricEvaluation::class, 'analytical_rule_set_id');
    }
}
