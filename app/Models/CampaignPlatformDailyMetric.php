<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CampaignPlatformDailyMetric extends Model
{
    protected $fillable = [
        'unique_key',
        'platform',
        'metric_date',
        'account_id',
        'account_name',
        'campaign_id',
        'campaign_name',
        'campaign_status',
        'campaign_effective_status',
        'campaign_start_date',
        'campaign_end_date',
        'advertising_channel_type',
        'advertising_channel_sub_type',
        'adset_id',
        'adset_name',
        'ad_group_id',
        'ad_group_name',
        'ad_id',
        'ad_name',
        'spend',
        'impressions',
        'clicks',
        'platform_leads',
        'platform_conversions',
        'currency',
        'raw_payload',
        'synced_at',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'campaign_start_date' => 'date',
        'campaign_end_date' => 'date',
        'spend' => 'decimal:2',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'platform_leads' => 'integer',
        'platform_conversions' => 'decimal:4',
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
    ];

    public static function uniqueKey(array $attributes): string
    {
        $parts = [
            $attributes['platform'] ?? 'unknown',
            $attributes['metric_date'] ?? '',
            $attributes['account_id'] ?? '',
            $attributes['campaign_id'] ?? '',
            $attributes['adset_id'] ?? '',
            $attributes['ad_group_id'] ?? '',
            $attributes['ad_id'] ?? '',
        ];

        return hash('sha256', Str::lower(implode('|', array_map(fn ($value) => trim((string) $value), $parts))));
    }
}
