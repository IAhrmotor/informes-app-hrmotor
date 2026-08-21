<?php

namespace App\Services\SeoAnalytics;

use Illuminate\Database\Eloquent\Builder;

final class SeoAnalyticalSnapshotScope
{
    public function __construct(
        private readonly SearchConsoleClient $searchConsole,
        private readonly GoogleAnalyticsClient $analytics,
    ) {}

    /** @return array<string, string> */
    public function currentIdentities(): array
    {
        $identities = [
            'salesforce' => hash('sha256', SeoAnalyticalMetricRegistry::SALESFORCE_SOURCE_IDENTIFIER),
        ];
        $searchProperty = $this->searchConsole->configuredProperty();
        if (is_string($searchProperty) && $searchProperty !== '') {
            $identities['search_console'] = hash('sha256', $searchProperty);
        }
        $ga4Property = $this->analytics->configuredPropertyId();
        if (is_string($ga4Property) && $ga4Property !== '') {
            $identities['ga4'] = hash('sha256', $ga4Property);
        }

        return $identities;
    }

    public function apply(Builder $query, string $prefix = ''): void
    {
        $identities = $this->currentIdentities();
        $query->where(function (Builder $identityGroup) use ($identities, $prefix): void {
            foreach ($identities as $source => $hash) {
                $method = $source === array_key_first($identities) ? 'where' : 'orWhere';
                $identityGroup->{$method}(function (Builder $identityQuery) use ($source, $hash, $prefix): void {
                    $identityQuery
                        ->where($prefix.'source_key', $source)
                        ->where($prefix.'source_identifier_hash', $hash);
                });
            }
        });
    }
}
