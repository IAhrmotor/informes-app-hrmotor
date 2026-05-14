<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceUser extends Model
{
    protected $fillable = [
        'salesforce_id',
        'name',
        'profile_name',
        'user_delegation',
        'is_active',
        'raw_payload',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'raw_payload' => 'array',
    ];
}
