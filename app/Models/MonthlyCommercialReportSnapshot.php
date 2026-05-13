<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyCommercialReportSnapshot extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'previous_period_start',
        'previous_period_end',
        'payload_json',
        'generated_at',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'previous_period_start' => 'datetime',
        'previous_period_end' => 'datetime',
        'payload_json' => 'array',
        'generated_at' => 'datetime',
    ];
}
