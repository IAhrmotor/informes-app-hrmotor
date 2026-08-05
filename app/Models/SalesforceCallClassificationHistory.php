<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceCallClassificationHistory extends Model
{
    protected $table = 'salesforce_call_classification_history';

    protected $guarded = [];

    protected $casts = [
        'raw_values' => 'array',
        'previous_classification' => 'array',
        'new_classification' => 'array',
        'classified_at' => 'datetime',
    ];
}
