<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterCallDelegationMapping extends Model
{
    protected $fillable = [
        'portal_original',
        'received_value',
        'type',
        'delegation_name',
        'commercial_group',
        'status',
        'valid_from',
        'valid_to',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];
}