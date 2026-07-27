<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialFinancingPenaltyImport extends Model
{
    protected $fillable = [
        'uploaded_by_report_user_id',
        'original_filename',
        'stored_path',
        'rows_read',
        'rows_imported',
        'rows_unmatched',
        'commission_months',
    ];

    protected $casts = [
        'commission_months' => 'array',
    ];

    public function penalties(): HasMany
    {
        return $this->hasMany(CommercialFinancingPenalty::class, 'import_id');
    }
}
