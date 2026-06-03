<?php

namespace App\Services\Campaigns;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleAdsClient
{
    public function configured(): bool
    {
        return filled(config('services.google_ads.developer_token'))
            && filled(config('services.google_ads.client_id'))
            && filled(config('services.google_ads.client_secret'))
            && filled(config('services.google_ads.refresh_token'))
            && config('services.google_ads.customer_ids') !== [];
    }

    public function missingConfigurationWarnings(): array
    {
        if ($this->configured()) {
            return [];
        }

        return ['Las credenciales de Google Ads no estan configuradas. Se usara la inversion cacheada si existe.'];
    }

    public function customerIds(): array
    {
        return config('services.google_ads.customer_ids', []);
    }

    public function searchDailyMetrics(string $customerId, CarbonInterface $start, CarbonInterface $end): array
    {
        $token = $this->accessToken();
        $apiVersion = config('services.google_ads.api_version', 'v22');
        $url = sprintf(
            'https://googleads.googleapis.com/%s/customers/%s/googleAds:searchStream',
            $apiVersion,
            preg_replace('/\D+/', '', $customerId)
        );

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'developer-token' => config('services.google_ads.developer_token'),
        ];

        if (filled(config('services.google_ads.login_customer_id'))) {
            $headers['login-customer-id'] = preg_replace('/\D+/', '', (string) config('services.google_ads.login_customer_id'));
        }

        $response = Http::timeout(120)
            ->withHeaders($headers)
            ->post($url, [
                'query' => $this->gaql($start, $end),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Google Ads API error: '.$response->body());
        }

        return collect($response->json())
            ->flatMap(fn (array $chunk) => $chunk['results'] ?? [])
            ->values()
            ->all();
    }

    private function accessToken(): string
    {
        $response = Http::asForm()
            ->timeout(60)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_ads.client_id'),
                'client_secret' => config('services.google_ads.client_secret'),
                'refresh_token' => config('services.google_ads.refresh_token'),
                'grant_type' => 'refresh_token',
            ]);

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new RuntimeException('Google OAuth token error: '.$response->body());
        }

        return (string) $response->json('access_token');
    }

    private function gaql(CarbonInterface $start, CarbonInterface $end): string
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        return <<<GAQL
SELECT
  segments.date,
  campaign.id,
  campaign.name,
  campaign.status,
  campaign.start_date,
  campaign.end_date,
  campaign.advertising_channel_type,
  campaign.advertising_channel_sub_type,
  metrics.impressions,
  metrics.clicks,
  metrics.conversions,
  metrics.all_conversions,
  metrics.cost_micros
FROM campaign
WHERE
  segments.date >= '{$startDate}'
  AND segments.date <= '{$endDate}'
  AND campaign.status IN ('ENABLED', 'PAUSED')
ORDER BY segments.date ASC
GAQL;
    }
}
