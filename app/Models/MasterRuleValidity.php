<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterRuleValidity extends Model
{
    protected $fillable = [
        'portal_original',
        'rule_name',
        'valid_from',
        'valid_to',
        'status',
        'notes',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];
}