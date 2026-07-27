<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialFinancingPenalty extends Model
{
    protected $fillable = [
        'import_id',
        'commission_month',
        'commercial_email',
        'salesforce_user_id',
        'amount',
        'source_sheet',
        'source_row',
        'raw_values',
        'is_active',
        'deactivated_at',
    ];

    protected $casts = [
        'commission_month' => 'date',
        'amount' => 'decimal:2',
        'raw_values' => 'array',
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(CommercialFinancingPenaltyImport::class, 'import_id');
    }
}
