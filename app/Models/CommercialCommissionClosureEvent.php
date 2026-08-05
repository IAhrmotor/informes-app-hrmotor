<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialCommissionClosureEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'closure_id', 'action', 'from_status', 'to_status', 'report_user_id', 'reason', 'context', 'created_at',
    ];

    protected $casts = ['context' => 'array', 'created_at' => 'datetime'];
}
