<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceCall extends Model
{
    protected $fillable = [
        'salesforce_id',
        'subject',
        'description',
        'type',
        'status',
        'priority',
        'activity_date',
        'created_date',
        'last_modified_date',
        'owner_id',
        'owner_name',
        'owner_profile_name',
        'who_id',
        'who_type',
        'what_id',
        'call_object',
        'call_duration_seconds',
        'parsed_duration_seconds',
        'adjusted_duration_seconds',
        'call_type_raw',
        'direction',
        'portales_raw',
        'call_origin',
        'portal_resolved',
        'portal_resolution_source',
        'result_raw',
        'call_status',
        'is_answered',
        'is_lost',
        'fixed_phone',
        'client_phone',
        'destination_raw',
        'destination_agent_code',
        'destination_agent_name',
        'operational_user_id',
        'operational_user_name',
        'normalized_user_key',
        'operational_team',
        'owner_team',
        'delegation',
        'zone',
        'is_overflow',
        'overflow_reason',
        'queue_raw',
        'uid_raw',
        'puid_raw',
        'call_started_at',
        'call_ended_at',
        'raw_payload',
        'parse_debug',
    ];

    protected $casts = [
        'activity_date' => 'date',
        'created_date' => 'datetime',
        'last_modified_date' => 'datetime',
        'call_started_at' => 'datetime',
        'call_ended_at' => 'datetime',
        'is_answered' => 'boolean',
        'is_lost' => 'boolean',
        'is_overflow' => 'boolean',
        'raw_payload' => 'array',
        'parse_debug' => 'array',
    ];
}
