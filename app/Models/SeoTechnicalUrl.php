<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoTechnicalUrl extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_strategic' => 'boolean',
        'is_search_console' => 'boolean',
        'search_console_rank' => 'integer',
        'search_console_clicks' => 'integer',
        'search_console_impressions' => 'integer',
        'in_sitemap' => 'boolean',
        'first_selected_at' => 'datetime',
        'last_selected_at' => 'datetime',
        'sitemap_checked_at' => 'datetime',
        'http_status' => 'integer',
        'redirect_count' => 'integer',
        'response_time_ms' => 'integer',
        'has_noindex' => 'boolean',
        'canonical_count' => 'integer',
        'canonical_matches_final' => 'boolean',
        'body_truncated' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public function checks(): HasMany
    {
        return $this->hasMany(SeoTechnicalUrlCheck::class);
    }
}
