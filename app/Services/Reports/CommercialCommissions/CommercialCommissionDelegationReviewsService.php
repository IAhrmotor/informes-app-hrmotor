<?php

namespace App\Services\Reports\CommercialCommissions;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CommercialCommissionDelegationReviewsService
{
    public function __construct(
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
    ) {
    }

    public function forMonthAndDelegations(CarbonImmutable $month, Collection $delegationLabels): array
    {
        $endpoint = trim((string) config('services.internal_reviews.endpoint', ''));

        if ($endpoint === '') {
            return [];
        }

        $monthParam = $month->format('m-y');
        $user = (string) config('services.internal_reviews.user', '');
        $password = (string) config('services.internal_reviews.password', '');
        $timeout = min(5, max(1, (int) config('services.internal_reviews.timeout', 5)));
        $results = [];
        $ttl = $this->cacheTtlForMonth($month);
        $normalizedLabels = $delegationLabels
            ->filter()
            ->map(fn (mixed $label) => (string) $label)
            ->unique()
            ->values();
        $uncachedLabels = collect();

        foreach ($normalizedLabels as $delegationLabel) {
            $cacheKey = $this->cacheKey($monthParam, $delegationLabel);
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                $results[$delegationLabel] = $this->normalizePayload($cached);
                continue;
            }

            $uncachedLabels->push($delegationLabel);
        }

        if ($uncachedLabels->isNotEmpty()) {
            try {
                $responses = Http::pool(function (Pool $pool) use ($uncachedLabels, $endpoint, $monthParam, $user, $password, $timeout): array {
                    $requests = [];

                    foreach ($uncachedLabels as $delegationLabel) {
                        $location = $this->formulaConfig->googleReviewsLocationForDelegation($delegationLabel);

                        if ($location === null) {
                            continue;
                        }

                        $requests[$delegationLabel] = $pool
                            ->as($delegationLabel)
                            ->acceptJson()
                            ->connectTimeout($timeout)
                            ->timeout($timeout)
                            ->withBasicAuth($user, $password)
                            ->get($endpoint, [
                                'month' => $monthParam,
                                'location' => $location,
                            ]);
                    }

                    return $requests;
                });
            } catch (\Throwable $exception) {
                report($exception);

                foreach ($uncachedLabels as $delegationLabel) {
                    $results[$delegationLabel] = $this->emptyPayload();
                }

                return $results;
            }

            foreach ($uncachedLabels as $delegationLabel) {
                $cacheKey = $this->cacheKey($monthParam, $delegationLabel);
                $response = $responses[$delegationLabel] ?? null;

                if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful()) {
                    $results[$delegationLabel] = $this->emptyPayload();
                    continue;
                }

                $payload = $this->normalizePayload((array) $response->json());
                $results[$delegationLabel] = $payload;
                Cache::put($cacheKey, $payload, $ttl);
            }
        }

        return $results;
    }

    private function cacheKey(string $monthParam, string $delegationLabel): string
    {
        return 'commercial-commissions:delegation-reviews:v3:'.$monthParam.':'.$this->formulaConfig->delegationKey($delegationLabel);
    }

    private function cacheTtlForMonth(CarbonImmutable $month): \DateTimeInterface
    {
        $minutes = max(1, (int) config('services.internal_reviews.cache_minutes', 15));

        return now()->addMinutes($minutes);
    }

    private function normalizePayload(array $payload): array
    {
        return [
            'reviews_count' => max(0, (int) data_get($payload, 'reviews_count', 0)),
            'average_rating' => is_numeric(data_get($payload, 'average_rating'))
                ? round((float) data_get($payload, 'average_rating'), 2)
                : null,
        ];
    }

    private function emptyPayload(): array
    {
        return [
            'reviews_count' => 0,
            'average_rating' => null,
        ];
    }
}
