<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSyncRun extends Model
{
    protected $fillable = [
        'dataset',
        'source',
        'status',
        'period_start_at',
        'period_end_at',
        'source_cutoff_at',
        'started_at',
        'completed_at',
        'timezone',
        'stats',
        'error_message',
    ];

    protected $casts = [
        'period_start_at' => 'datetime',
        'period_end_at' => 'datetime',
        'source_cutoff_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'stats' => 'array',
    ];
}
