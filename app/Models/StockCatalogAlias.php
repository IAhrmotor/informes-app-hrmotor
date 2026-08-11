<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCatalogAlias extends Model
{
    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_LEGACY_UNVERIFIED = 'legacy_unverified';

    protected $guarded = [];

    public function catalogValue(): BelongsTo
    {
        return $this->belongsTo(StockCatalogValue::class, 'stock_catalog_value_id');
    }
}
