<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoSalesforceOrganicDailyMetric;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;

final class SalesforceOrganicLeadSyncService
{
    public const DATASET = 'seo_salesforce_organic';

    public function __construct(private readonly SalesforceClient $client) {}

    public function configured(): bool
    {
        $mode = config('salesforce.auth_mode');

        return filled(config('salesforce.token_url'))
            && filled(config('salesforce.client_id'))
            && filled(config('salesforce.client_secret'))
            && in_array($mode, ['client_credentials', 'refresh_token'], true)
            && ($mode !== 'refresh_token' || filled(config('salesforce.refresh_token')));
    }

    /** @return array<string, mixed> */
    public function sync(int $days): array
    {
        $timezone = (string) config('seo_analytics.timezone', 'Europe/Madrid');
        $cutoff = CarbonImmutable::now($timezone)->startOfDay()->subDay();
        $start = $cutoff->subDays($days - 1);
        $endExclusive = $cutoff->addDay();
        $counts = [];
        $queried = 0;
        $organicRows = 0;

        foreach ($this->client->queryPages($this->soql($start, $endExclusive)) as $records) {
            foreach ($records as $record) {
                $queried++;
                if (blank($record['Id'] ?? null) || blank($record['CreatedDate'] ?? null)) {
                    continue;
                }

                $date = CarbonImmutable::parse($record['CreatedDate'])->setTimezone($timezone)->toDateString();
                if ($date < $start->toDateString() || $date > $cutoff->toDateString()) {
                    continue;
                }

                $counts[$date] = ($counts[$date] ?? 0) + 1;
                $organicRows++;
            }
        }

        $now = now();
        $rows = [];
        for ($date = $start; $date->lessThanOrEqualTo($cutoff); $date = $date->addDay()) {
            $rows[] = [
                'data_date' => $date->toDateString(),
                'lead_count' => $counts[$date->toDateString()] ?? 0,
                'source_timezone' => $timezone,
                'extracted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SeoSalesforceOrganicDailyMetric::query()->upsert(
            $rows,
            ['data_date'],
            ['lead_count', 'source_timezone', 'extracted_at', 'updated_at'],
        );

        return [
            'period_start' => $start,
            'period_end' => $cutoff,
            'cutoff' => $cutoff,
            'stats' => [
                'queried' => $queried,
                'organic_rows' => $organicRows,
                'days_persisted' => count($rows),
                'range_days' => $days,
            ],
        ];
    }

    public function soql(CarbonImmutable $start, CarbonImmutable $endExclusive): string
    {
        return sprintf(
            "SELECT Id, CreatedDate FROM Lead WHERE IsDeleted = false AND Medio_origen__c = 'Orgánico' AND CreatedDate >= %s AND CreatedDate < %s ORDER BY CreatedDate ASC",
            $start->utc()->format('Y-m-d\TH:i:s\Z'),
            $endExclusive->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
