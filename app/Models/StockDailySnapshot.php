<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockDailySnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'snapshot_date' => 'date',
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'entry_date' => 'date',
        'days_in_stock' => 'integer',
    ];
}
