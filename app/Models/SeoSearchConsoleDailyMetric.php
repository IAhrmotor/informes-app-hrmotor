<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSearchConsoleDailyMetric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'data_date' => 'date',
        'clicks' => 'integer',
        'impressions' => 'integer',
        'ctr' => 'decimal:8',
        'position' => 'decimal:4',
        'is_final' => 'boolean',
        'extracted_at' => 'datetime',
    ];
}
