<?php

namespace App\Services\Campaigns;

use App\Models\CampaignPlatformDailyMetric;
use Carbon\CarbonImmutable;

class CampaignMetricsRepository
{
    public function upsert(array $attributes): CampaignPlatformDailyMetric
    {
        $attributes['platform'] = $attributes['platform'] ?? 'unknown';
        $attributes['metric_date'] = CarbonImmutable::parse($attributes['metric_date'])->toDateString();
        $attributes['unique_key'] = CampaignPlatformDailyMetric::uniqueKey($attributes);
        $attributes['synced_at'] = $attributes['synced_at'] ?? CarbonImmutable::now();

        return CampaignPlatformDailyMetric::query()->updateOrCreate(
            ['unique_key' => $attributes['unique_key']],
            $attributes
        );
    }
}
