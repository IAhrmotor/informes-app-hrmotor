<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CampaignPlatformIdentifier extends Model
{
    protected $fillable = [
        'unique_key',
        'platform',
        'account_id',
        'campaign_id',
        'campaign_name',
        'adset_id',
        'adset_name',
        'ad_group_id',
        'ad_group_name',
        'ad_id',
        'ad_name',
        'raw_payload',
        'synced_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
    ];

    public static function uniqueKey(array $attributes): string
    {
        return hash('sha256', Str::lower(implode('|', [
            trim((string) ($attributes['platform'] ?? 'unknown')),
            trim((string) ($attributes['account_id'] ?? '')),
            trim((string) ($attributes['campaign_id'] ?? '')),
            trim((string) ($attributes['adset_id'] ?? '')),
            trim((string) ($attributes['ad_group_id'] ?? '')),
            trim((string) ($attributes['ad_id'] ?? '')),
        ])));
    }
}
