<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallAgentMapping extends Model
{
    protected $fillable = [
        'salesforce_user_id',
        'agent_code',
        'user_name',
        'normalized_name',
        'team_type',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
