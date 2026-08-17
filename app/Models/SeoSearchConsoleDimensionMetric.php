<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSearchConsoleDimensionMetric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_days' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'rank' => 'integer',
        'clicks' => 'integer',
        'impressions' => 'integer',
        'ctr' => 'decimal:8',
        'position' => 'decimal:4',
        'extracted_at' => 'datetime',
    ];
}
