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

    /** @return array{configured: bool, property_id: ?string, accessible: ?bool, metadata: bool, dimensions: int, metrics: int, timezone: ?string, key_events: array<int, string>, data_streams: array<int, array{name: string, type: string, display_name: string, default_uri: ?string}>, web_stream_count: int} */
    public function diagnose(): array
    {
        $propertyId = $this->configuredPropertyId();

        if (! $this->configured()) {
            return $this->emptyResult($propertyId);
        }

        $propertyName = 'properties/'.$propertyId;
        $property = $this->property();
        $metadata = $this->metadata();
        $dataStreams = $this->dataStreams();

        return [
            'configured' => true,
            'property_id' => $propertyId,
            'accessible' => ($property['name'] ?? null) === $propertyName,
            'metadata' => filled($metadata['name'] ?? null),
            'dimensions' => count($metadata['dimensions'] ?? []),
            'metrics' => count($metadata['metrics'] ?? []),
            'timezone' => filled($property['timeZone'] ?? null) ? (string) $property['timeZone'] : null,
            'key_events' => $this->keyEvents(),
            'data_streams' => $dataStreams,
            'web_stream_count' => collect($dataStreams)->where('type', 'WEB_DATA_STREAM')->count(),
        ];
    }

    public function configuredPropertyId(): ?string
    {
        $propertyId = trim((string) config('services.google_analytics.property_id'));

        return $propertyId === '' ? null : $propertyId;
    }

    /** @return array<string, mixed> */
    public function property(): array
    {
        [$token, $configuration, $propertyName] = $this->requestContext();

        return $this->get(
            $token,
            rtrim((string) $configuration['admin_api_url'], '/').'/'.$propertyName,
            'Google Analytics Admin API'
        )->json() ?? [];
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        [$token, $configuration, $propertyName] = $this->requestContext();

        return $this->get(
            $token,
            rtrim((string) $configuration['data_api_url'], '/').'/'.$propertyName.'/metadata',
            'Google Analytics Data API'
        )->json() ?? [];
    }

    /** @return array<int, array{name: string, type: string, display_name: string, default_uri: ?string}> */
    public function dataStreams(): array
    {
        [$token, $configuration, $propertyName] = $this->requestContext();
        $url = rtrim((string) $configuration['admin_api_url'], '/').'/'.$propertyName.'/dataStreams';
        $streams = [];

        foreach ($this->paginatedAdminResources($token, $url, 'Google Analytics Data Streams', 'dataStreams') as $stream) {
            if (! is_array($stream) || blank($stream['name'] ?? null) || blank($stream['type'] ?? null)) {
                continue;
            }

            $streams[] = [
                'name' => (string) $stream['name'],
                'type' => (string) $stream['type'],
                'display_name' => (string) ($stream['displayName'] ?? ''),
                'default_uri' => ($stream['type'] ?? null) === 'WEB_DATA_STREAM' && filled(data_get($stream, 'webStreamData.defaultUri'))
                    ? (string) data_get($stream, 'webStreamData.defaultUri')
                    : null,
            ];
        }

        return $streams;
    }

    /** @return array<int, string> */
    public function keyEvents(): array
    {
        [$token, $configuration, $propertyName] = $this->requestContext();
        $url = rtrim((string) $configuration['admin_api_url'], '/').'/'.$propertyName.'/keyEvents';
        $events = [];

        foreach ($this->paginatedAdminResources($token, $url, 'Google Analytics Key Events', 'keyEvents') as $event) {
            if (is_array($event) && filled($event['eventName'] ?? null)) {
                $events[] = (string) $event['eventName'];
            }
        }

        return array_values(array_unique($events));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function runReport(array $payload): array
    {
        [$token, $configuration, $propertyName] = $this->requestContext();
        $url = rtrim((string) $configuration['data_api_url'], '/').'/'.$propertyName.':runReport';

        return $this->post($token, $url, 'Google Analytics Data API', $payload)->json() ?? [];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: string} */
    private function requestContext(): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Google Analytics no configurado.');
        }

        $configuration = config('services.google_analytics');
        $propertyName = 'properties/'.$this->configuredPropertyId();

        return [
            $this->tokens->accessToken('Google Analytics', $configuration),
            $configuration,
            $propertyName,
        ];
    }

    /** @return array<int, mixed> */
    private function paginatedAdminResources(
        string $token,
        string $url,
        string $integration,
        string $resourceKey,
    ): array {
        $resources = [];
        $pageToken = null;
        $seenTokens = [];

        for ($page = 1; $page <= 100; $page++) {
            $query = ['pageSize' => 200];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->get($token, $url, $integration, $query)->json() ?? [];
            if (! is_array($payload[$resourceKey] ?? [])) {
                throw new RuntimeException($integration.': respuesta paginada invalida.');
            }
            array_push($resources, ...$payload[$resourceKey]);

            $pageToken = filled($payload['nextPageToken'] ?? null) ? (string) $payload['nextPageToken'] : null;
            if ($pageToken === null) {
                return $resources;
            }
            if (isset($seenTokens[$pageToken])) {
                throw new RuntimeException($integration.': token de paginacion repetido.');
            }
            $seenTokens[$pageToken] = true;
        }

        throw new RuntimeException($integration.': limite de paginacion excedido.');
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

    /** @param array<string, mixed> $payload */
    private function post(string $token, string $url, string $integration, array $payload): Response
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout((int) config('seo_analytics.http.connect_timeout', 5))
            ->timeout((int) config('seo_analytics.http.timeout', 20))
            ->post($url, $payload);

        if ($response->failed()) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                $integration,
                $response->status(),
                $response->json()
            ));
        }

        return $response;
    }

    /** @return array{configured: false, property_id: ?string, accessible: null, metadata: false, dimensions: 0, metrics: 0, timezone: null, key_events: array<int, string>, data_streams: array<int, never>, web_stream_count: 0} */
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
            'data_streams' => [],
            'web_stream_count' => 0,
        ];
    }
}
