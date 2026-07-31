<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesforceSaleSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'signed_date' => 'date',
        'vehicle_entry_date' => 'date',
        'vehicle_mileage' => 'integer',
        'rotation_days' => 'integer',
        'sale_management' => 'boolean',
        'sale_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'trade_in_amount' => 'decimal:2',
        'management_cost' => 'decimal:2',
        'logistics_cost' => 'decimal:2',
        'transfer_cost' => 'decimal:2',
        'warranty_amount' => 'decimal:2',
        'plan_auto_plus_amount' => 'decimal:2',
        'cae_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'financial_discount_amount' => 'decimal:2',
        'logistics_discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'quality_issues' => 'array',
        'source_payload' => 'array',
        'captured_at' => 'datetime',
        'is_valid' => 'boolean',
        'validity_checked_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(StockDelegation::class, 'stock_delegation_id');
    }
}
