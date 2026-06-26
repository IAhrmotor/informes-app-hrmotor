<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialCommissionMonthSetting extends Model
{
    protected $fillable = [
        'month',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];
}
