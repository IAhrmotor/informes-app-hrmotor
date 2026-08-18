<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoGa4OrganicDailyMetric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'data_date' => 'date',
        'key_events' => 'decimal:6',
        'extracted_at' => 'datetime',
    ];
}
