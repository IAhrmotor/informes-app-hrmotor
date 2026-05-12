<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterPortal extends Model
{
    protected $fillable = [
        'portal_original',
        'portal_group',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}