<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesforceVehicle extends Model
{
    public const STOCK_STATES = ['Disponible', 'Reservado', 'Bloqueado'];

    protected $fillable = [
        'salesforce_id',
        'name',
        'sku',
        'plate',
        'brand',
        'model',
        'version',
        'segment',
        'fuel',
        'body',
        'mileage',
        'state',
        'stock_delegation_id',
        'salesforce_delegation_id',
        'salesforce_delegation_name',
        'purchase_price',
        'sale_price',
        'normal_sale_price',
        'financed_sale_price',
        'only_financed',
        'entry_date',
        'buyer_id',
        'buyer_name',
        'purchase_source',
        'is_in_stock',
        'last_seen_stock_at',
        'raw_payload',
    ];

    protected $casts = [
        'mileage' => 'integer',
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'normal_sale_price' => 'decimal:2',
        'financed_sale_price' => 'decimal:2',
        'only_financed' => 'boolean',
        'entry_date' => 'date',
        'is_in_stock' => 'boolean',
        'last_seen_stock_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(StockDelegation::class, 'stock_delegation_id');
    }

    public function dailySnapshots(): HasMany
    {
        return $this->hasMany(StockDailySnapshot::class);
    }
}
