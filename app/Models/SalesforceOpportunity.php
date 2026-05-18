<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceOpportunity extends Model
{
    protected $fillable = [
        'salesforce_id',
        'name',
        'created_date',
        'close_date',
        'stage_name',
        'record_type_name',
        'owner_id',
        'owner_name',
        'owner_delegation',
        'account_id',
        'account_name',
        'account_phone',
        'account_person_email',
        'account_company_email',
        'portal_original',
        'portal_resolved',
        'portal_resolution_source',
        'portal_resolution_lead_id',
        'portal_resolution_debug',
        'reservation',
        'reservation_date',
        'cv_signed',
        'cv_signed_date',
        'raw_payload',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'close_date' => 'date',
        'reservation' => 'boolean',
        'reservation_date' => 'date',
        'cv_signed' => 'boolean',
        'cv_signed_date' => 'date',
        'portal_resolution_debug' => 'array',
        'raw_payload' => 'array',
    ];
}
