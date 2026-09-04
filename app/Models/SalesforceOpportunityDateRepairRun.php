<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceOpportunityDateRepairRun extends Model
{
    protected $fillable = [
        'run_identifier',
        'reason',
        'status',
        'started_at',
        'finished_at',
        'rows_examined',
        'rows_changed',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'rows_examined' => 'integer',
        'rows_changed' => 'integer',
    ];
}
