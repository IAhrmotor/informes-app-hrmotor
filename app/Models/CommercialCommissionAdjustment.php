<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialCommissionAdjustment extends Model
{
    protected $fillable = [
        'operation_id', 'original_month', 'application_month', 'amount', 'reason', 'status',
        'created_by', 'applied_at', 'source_context',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'applied_at' => 'datetime',
        'source_context' => 'array',
    ];
}
