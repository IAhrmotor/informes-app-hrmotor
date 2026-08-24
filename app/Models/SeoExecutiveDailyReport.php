<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoExecutiveDailyReport extends Model
{
    protected $fillable = [
        'report_date',
        'generated_at',
        'payload',
        'payload_hash',
    ];

    protected $casts = [
        'report_date' => 'date',
        'generated_at' => 'datetime',
        'payload' => 'array',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(SeoExecutiveEmailDelivery::class);
    }
}
