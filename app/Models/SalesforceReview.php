<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceReview extends Model
{
    protected $fillable = [
        'salesforce_id',
        'created_date',
        'owner_id',
        'owner_name',
        'opportunity_salesforce_id',
        'opportunity_name',
        'opportunity_owner_id',
        'opportunity_owner_name',
        'opportunity_record_type_name',
        'opportunity_cv_signed_date',
        'raw_payload',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'opportunity_cv_signed_date' => 'date',
        'raw_payload' => 'array',
    ];
}
