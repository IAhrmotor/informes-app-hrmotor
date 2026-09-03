<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceOpportunityPortalReprocessHistory extends Model
{
    protected $table = 'salesforce_opportunity_portal_reprocess_history';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'changed_fields' => 'array',
        'previous_values' => 'array',
        'new_values' => 'array',
        'recorded_at' => 'datetime',
    ];
}
