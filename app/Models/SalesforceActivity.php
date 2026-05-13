<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceActivity extends Model
{
    protected $fillable = [
        'salesforce_id',
        'lead_salesforce_id',
        'activity_kind',
        'owner_id',
        'owner_name',
        'created_by_id',
        'created_by_name',
        'created_date',
        'activity_date',
        'subject',
        'type',
        'status',
        'raw_payload',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'activity_date' => 'date',
        'raw_payload' => 'array',
    ];
}
