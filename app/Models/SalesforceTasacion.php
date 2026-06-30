<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceTasacion extends Model
{
    protected $table = 'salesforce_tasaciones';

    protected $fillable = [
        'salesforce_id',
        'name',
        'created_date',
        'opportunity_salesforce_id',
        'opportunity_name',
        'contract_signed_date',
        'cv_signed',
        'tracking_name',
        'negotiation_1',
        'negotiation_2',
        'negotiation_3',
        'negotiation_4',
        'source_query_profile',
        'raw_payload',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'contract_signed_date' => 'date',
        'cv_signed' => 'boolean',
        'raw_payload' => 'array',
    ];
}
