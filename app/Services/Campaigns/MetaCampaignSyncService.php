<?php

namespace App\Services\Campaigns;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetaCampaignSyncService
{
    public function __construct(
        private readonly MetaAdsClient $client,
        private readonly CampaignMetricsRepository $metrics,
    ) {
    }

    public function sync(CarbonInterface $start, CarbonInterface $end): array
    {
        $warnings = $this->client->missingConfigurationWarnings();

        if ($warnings !== []) {
            foreach ($warnings as $warning) {
                Log::warning($warning);
            }

            return [
                'configured' => false,
                'processed' => 0,
                'saved' => 0,
                'warnings' => $warnings,
            ];
        }

        $processed = 0;
        $saved = 0;

        foreach ($this->client->adAccountIds() as $accountId) {
            try {
                $rows = $this->client->insights($accountId, $start, $end);
            } catch (Throwable $exception) {
                $warnings[] = "No se ha podido actualizar la inversion de Meta Ads ({$accountId}). ".$exception->getMessage();
                Log::warning(end($warnings));

                continue;
            }

            foreach ($rows as $row) {
                $processed++;
                $this->metrics->upsert([
                    'platform' => 'meta',
                    'metric_date' => data_get($row, 'date_start'),
                    'account_id' => data_get($row, 'account_id') ?: $accountId,
                    'account_name' => data_get($row, 'account_name'),
                    'campaign_id' => data_get($row, 'campaign_id'),
                    'campaign_name' => data_get($row, 'campaign_name'),
                    'adset_id' => data_get($row, 'adset_id'),
                    'adset_name' => data_get($row, 'adset_name'),
                    'ad_id' => data_get($row, 'ad_id'),
                    'ad_name' => data_get($row, 'ad_name'),
                    'spend' => (float) data_get($row, 'spend', 0),
                    'impressions' => (int) data_get($row, 'impressions', 0),
                    'clicks' => (int) data_get($row, 'clicks', 0),
                    'platform_leads' => $this->extractLeads(data_get($row, 'actions', [])),
                    'currency' => data_get($row, 'account_currency'),
                    'raw_payload' => $row,
                ]);
                $saved++;
            }
        }

        $this->invalidateCache();

        return [
            'configured' => true,
            'processed' => $processed,
            'saved' => $saved,
            'warnings' => $warnings,
        ];
    }

    private function extractLeads(mixed $actions): ?int
    {
        if (! is_array($actions)) {
            return null;
        }

        $leadActionTypes = [
            'lead',
            'onsite_conversion.lead_grouped',
            'onsite_conversion.messaging_conversation_started_7d',
            'offsite_conversion.fb_pixel_lead',
        ];

        $total = 0;
        $found = false;

        foreach ($actions as $action) {
            if (! in_array((string) data_get($action, 'action_type'), $leadActionTypes, true)) {
                continue;
            }

            $total += (int) data_get($action, 'value', 0);
            $found = true;
        }

        return $found ? $total : null;
    }

    private function invalidateCache(): void
    {
        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
    }
}
