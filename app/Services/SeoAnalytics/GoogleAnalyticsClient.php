<?php

namespace App\Services\SeoAnalytics;

use App\Support\IntegrationErrorSanitizer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleAnalyticsClient
{
    public function __construct(
        private readonly GoogleOAuthTokenProvider $tokens,
    ) {}

    public function configured(): bool
    {
        return collect(['client_id', 'client_secret', 'refresh_token', 'property_id'])
            ->every(fn (string $key): bool => filled(config('services.google_analytics.'.$key)))
            && ctype_digit((string) config('services.google_analytics.property_id'));
    }

    /** @return array{configured: bool, property_id: ?string, accessible: ?bool, metadata: bool, dimensions: int, metrics: int, timezone: ?string, key_events: array<int, string>} */
    public function diagnose(): array
    {
        $propertyId = $this->configuredPropertyId();

        if (! $this->configured()) {
            return $this->emptyResult($propertyId);
        }

        $configuration = config('services.google_analytics');
        $token = $this->tokens->accessToken('Google Analytics', $configuration);
        $propertyName = 'properties/'.$propertyId;

        $property = $this->get(
            $token,
            rtrim((string) $configuration['admin_api_url'], '/').'/'.$propertyName,
            'Google Analytics Admin API'
        )->json() ?? [];

        $metadata = $this->get(
            $token,
            rtrim((string) $configuration['data_api_url'], '/').'/'.$propertyName.'/metadata',
            'Google Analytics Data API'
        )->json() ?? [];

        return [
            'configured' => true,
            'property_id' => $propertyId,
            'accessible' => ($property['name'] ?? null) === $propertyName,
            'metadata' => filled($metadata['name'] ?? null),
            'dimensions' => count($metadata['dimensions'] ?? []),
            'metrics' => count($metadata['metrics'] ?? []),
            'timezone' => filled($property['timeZone'] ?? null) ? (string) $property['timeZone'] : null,
            'key_events' => $this->keyEvents($token, $configuration, $propertyName),
        ];
    }

    public function configuredPropertyId(): ?string
    {
        $propertyId = trim((string) config('services.google_analytics.property_id'));

        return $propertyId === '' ? null : $propertyId;
    }

    /** @param array<string, mixed> $configuration
     * @return array<int, string>
     */
    private function keyEvents(string $token, array $configuration, string $propertyName): array
    {
        $url = rtrim((string) $configuration['admin_api_url'], '/').'/'.$propertyName.'/keyEvents';
        $events = [];
        $pageToken = null;
        $seenTokens = [];
        $page = 0;

        do {
            $page++;

            if ($page > 100) {
                throw new RuntimeException('Google Analytics Key Events: limite de paginacion excedido.');
            }

            $query = ['pageSize' => 200];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->get($token, $url, 'Google Analytics Key Events', $query)->json() ?? [];

            foreach ($payload['keyEvents'] ?? [] as $event) {
                if (is_array($event) && filled($event['eventName'] ?? null)) {
                    $events[] = (string) $event['eventName'];
                }
            }

            $pageToken = filled($payload['nextPageToken'] ?? null)
                ? (string) $payload['nextPageToken']
                : null;

            if ($pageToken !== null && isset($seenTokens[$pageToken])) {
                throw new RuntimeException('Google Analytics Key Events: token de paginacion repetido.');
            }

            if ($pageToken !== null) {
                $seenTokens[$pageToken] = true;
            }
        } while ($pageToken !== null);

        return array_values(array_unique($events));
    }

    /** @param array<string, int|string> $query */
    private function get(string $token, string $url, string $integration, array $query = []): Response
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout((int) config('seo_analytics.http.connect_timeout', 5))
            ->timeout((int) config('seo_analytics.http.timeout', 20))
            ->get($url, $query);

        if ($response->failed()) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                $integration,
                $response->status(),
                $response->json()
            ));
        }

        return $response;
    }

    /** @return array{configured: false, property_id: ?string, accessible: null, metadata: false, dimensions: 0, metrics: 0, timezone: null, key_events: array<int, string>} */
    private function emptyResult(?string $propertyId): array
    {
        return [
            'configured' => false,
            'property_id' => $propertyId,
            'accessible' => null,
            'metadata' => false,
            'dimensions' => 0,
            'metrics' => 0,
            'timezone' => null,
            'key_events' => [],
        ];
    }
}
