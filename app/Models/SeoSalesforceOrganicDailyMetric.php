<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSalesforceOrganicDailyMetric extends Model
{
    protected $guarded = [];

    protected $casts = [
        'data_date' => 'date',
        'lead_count' => 'integer',
        'extracted_at' => 'datetime',
    ];
}
