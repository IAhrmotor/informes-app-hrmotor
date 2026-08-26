<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialPerformanceMonthlyTarget extends Model
{
    public const DEFAULT_RESERVATIONS_TARGET = 18;

    protected $fillable = [
        'month',
        'reservations_target',
        'is_explicit',
        'updated_by_report_user_id',
    ];

    protected $casts = [
        'month' => 'date',
        'reservations_target' => 'integer',
        'is_explicit' => 'boolean',
    ];
}
