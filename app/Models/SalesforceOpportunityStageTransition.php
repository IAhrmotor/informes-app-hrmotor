<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceOpportunityStageTransition extends Model
{
    protected $fillable = [
        'salesforce_history_id',
        'opportunity_salesforce_id',
        'previous_stage',
        'new_stage',
        'transitioned_at',
        'reservation_date',
        'owner_id',
        'owner_name',
        'source',
        'is_reservation_cancellation',
        'quality_status',
        'synced_at',
    ];

    protected $casts = [
        'transitioned_at' => 'datetime',
        'reservation_date' => 'date',
        'synced_at' => 'datetime',
        'is_reservation_cancellation' => 'boolean',
    ];
}
