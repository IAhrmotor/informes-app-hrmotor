<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialCommissionSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'closure_id', 'month', 'version', 'formula_version', 'data_cutoff_at', 'payload', 'source_state', 'created_by', 'created_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'data_cutoff_at' => 'datetime',
        'payload' => 'array',
        'source_state' => 'array',
        'created_at' => 'datetime',
    ];
}
