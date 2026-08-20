<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticalMetricSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'data_date' => 'date',
        'source_cutoff_at' => 'datetime',
        'current_value' => 'decimal:8',
        'd7_value' => 'decimal:8',
        'd14_value' => 'decimal:8',
        'd21_value' => 'decimal:8',
        'd28_value' => 'decimal:8',
        'reference_count' => 'integer',
        'baseline_value' => 'decimal:8',
        'absolute_change' => 'decimal:8',
        'relative_change' => 'decimal:8',
        'd364_value' => 'decimal:8',
        'year_absolute_change' => 'decimal:8',
        'year_relative_change' => 'decimal:8',
        'is_evaluable' => 'boolean',
        'computed_at' => 'datetime',
    ];
}
