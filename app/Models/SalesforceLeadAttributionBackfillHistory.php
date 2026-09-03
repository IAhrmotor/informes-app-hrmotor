<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceLeadAttributionBackfillHistory extends Model
{
    protected $table = 'salesforce_lead_attribution_backfill_history';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'changed_fields' => 'array',
        'previous_values' => 'array',
        'new_values' => 'array',
        'recorded_at' => 'datetime',
    ];
}
