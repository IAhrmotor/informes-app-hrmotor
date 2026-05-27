<?php

namespace App\Services\Campaigns;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaAdsClient
{
    public function configured(): bool
    {
        return filled(config('services.meta_ads.access_token'))
            && config('services.meta_ads.ad_account_ids') !== [];
    }

    public function missingConfigurationWarnings(): array
    {
        if ($this->configured()) {
            return [];
        }

        return ['Las credenciales de Meta Ads no estan configuradas. Se usara la inversion cacheada si existe.'];
    }

    public function adAccountIds(): array
    {
        return config('services.meta_ads.ad_account_ids', []);
    }

    public function insights(string $accountId, CarbonInterface $start, CarbonInterface $end): array
    {
        $account = str_starts_with($accountId, 'act_') ? $accountId : 'act_'.$accountId;
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/insights',
            config('services.meta_ads.api_version', 'v22.0'),
            $account
        );

        $params = [
            'access_token' => config('services.meta_ads.access_token'),
            'level' => 'ad',
            'time_increment' => 1,
            'time_range' => json_encode([
                'since' => $start->toDateString(),
                'until' => $end->toDateString(),
            ]),
            'fields' => implode(',', [
                'date_start',
                'date_stop',
                'account_id',
                'account_name',
                'campaign_id',
                'campaign_name',
                'adset_id',
                'adset_name',
                'ad_id',
                'ad_name',
                'spend',
                'impressions',
                'clicks',
                'actions',
            ]),
            'limit' => 500,
        ];

        $rows = [];

        do {
            $response = Http::timeout(120)->get($url, $params);

            if ($response->failed()) {
                throw new RuntimeException('Meta Ads API error: '.$response->body());
            }

            $payload = $response->json();
            $rows = array_merge($rows, $payload['data'] ?? []);
            $url = data_get($payload, 'paging.next');
            $params = [];
        } while (filled($url));

        return $rows;
    }
}
