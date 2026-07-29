<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockDelegation extends Model
{
    protected $fillable = [
        'salesforce_id',
        'salesforce_name',
        'canonical_name',
        'normalized_key',
        'commercial_group',
        'zone',
        'capacity_total',
        'capacity_source_name',
        'capacity_updated_at',
        'is_commercial',
    ];

    protected $casts = [
        'capacity_total' => 'integer',
        'capacity_updated_at' => 'datetime',
        'is_commercial' => 'boolean',
    ];

    public function vehicles(): HasMany
    {
        return $this->hasMany(SalesforceVehicle::class);
    }
}
