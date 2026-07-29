<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceLogistic extends Model
{
    protected $guarded = [];

    protected $casts = [
        'transport_date' => 'date',
        'reception_date' => 'date',
        'destination_date' => 'date',
        'salesforce_last_modified_at' => 'datetime',
        'raw_payload' => 'array',
    ];
}
