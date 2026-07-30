<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAvailabilityAlert extends Model
{
    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'task_created_at' => 'datetime',
        'email_sent_at' => 'datetime',
    ];

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(StockDelegation::class, 'stock_delegation_id');
    }
}
