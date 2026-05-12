<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterDelegation extends Model
{
    protected $fillable = [
        'delegation_name',
        'commercial_group',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}