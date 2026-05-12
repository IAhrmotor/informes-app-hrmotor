<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadNormalized extends Model
{
    protected $table = 'leads_normalized';

    protected $fillable = [
        'lead_raw_id',
        'lead_created_at',
        'channel_direction',
        'portal_original',
        'portal_group',
        'delegation_name',
        'commercial_group',
        'commercial_name',
        'is_exposition',
        'is_converted',
        'is_discarded',
        'is_potential',
        'has_task_event',
        'has_recent_follow_up',
        'minutes_to_assignment',
        'minutes_to_first_task_event',
        'data_quality_status',
        'data_quality_issue',
    ];

    protected $casts = [
        'lead_created_at' => 'datetime',
        'is_exposition' => 'boolean',
        'is_converted' => 'boolean',
        'is_discarded' => 'boolean',
        'is_potential' => 'boolean',
        'has_task_event' => 'boolean',
        'has_recent_follow_up' => 'boolean',
    ];

    public function raw(): BelongsTo
    {
        return $this->belongsTo(LeadRaw::class, 'lead_raw_id');
    }
}