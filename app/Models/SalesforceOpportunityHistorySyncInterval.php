<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceOpportunityHistorySyncInterval extends Model
{
    protected $fillable = [
        'range_start',
        'range_end',
        'completed_at',
        'source',
        'queried_rows',
        'unresolved_dependencies',
        'is_kpi_certified',
    ];

    protected $casts = [
        'range_start' => 'datetime',
        'range_end' => 'datetime',
        'completed_at' => 'datetime',
        'queried_rows' => 'integer',
        'unresolved_dependencies' => 'integer',
        'is_kpi_certified' => 'boolean',
    ];
}
