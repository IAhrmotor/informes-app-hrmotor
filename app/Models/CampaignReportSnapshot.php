<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignReportSnapshot extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'filters_hash',
        'summary',
        'campaigns',
        'rankings',
        'warnings',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'summary' => 'array',
        'campaigns' => 'array',
        'rankings' => 'array',
        'warnings' => 'array',
    ];
}
