<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoTechnicalUrlCheck extends Model
{
    protected $guarded = [];

    protected $casts = [
        'check_date' => 'date',
        'checked_at' => 'datetime',
        'http_status' => 'integer',
        'redirect_count' => 'integer',
        'response_time_ms' => 'integer',
        'is_html' => 'boolean',
        'has_noindex' => 'boolean',
        'canonical_count' => 'integer',
        'canonical_matches_final' => 'boolean',
        'body_truncated' => 'boolean',
    ];

    public function technicalUrl(): BelongsTo
    {
        return $this->belongsTo(SeoTechnicalUrl::class, 'seo_technical_url_id');
    }
}
