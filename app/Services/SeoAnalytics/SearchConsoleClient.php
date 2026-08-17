<?php

namespace App\Services\SeoAnalytics;

use App\Support\IntegrationErrorSanitizer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SearchConsoleClient
{
    public function __construct(
        private readonly GoogleOAuthTokenProvider $tokens,
    ) {}

    public function configured(): bool
    {
        return collect(['client_id', 'client_secret', 'refresh_token', 'property'])
            ->every(fn (string $key): bool => filled(config('services.google_search_console.'.$key)));
    }

    /** @return array{configured: bool, property: ?string, accessible: ?bool, sites: array<int, array{property: string, permission: string}>} */
    public function diagnose(): array
    {
        $property = $this->configuredProperty();

        if (! $this->configured()) {
            return ['configured' => false, 'property' => $property, 'accessible' => null, 'sites' => []];
        }

        $configuration = config('services.google_search_console');
        $token = $this->tokens->accessToken('Search Console', $configuration);
        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout((int) config('seo_analytics.http.connect_timeout', 5))
            ->timeout((int) config('seo_analytics.http.timeout', 20))
            ->get((string) $configuration['sites_url']);

        if ($response->failed()) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                'Search Console API',
                $response->status(),
                $response->json()
            ));
        }

        $sites = collect($response->json('siteEntry', []))
            ->filter(fn (mixed $site): bool => is_array($site) && filled($site['siteUrl'] ?? null))
            ->map(fn (array $site): array => [
                'property' => (string) $site['siteUrl'],
                'permission' => (string) ($site['permissionLevel'] ?? 'unknown'),
            ])
            ->values()
            ->all();

        return [
            'configured' => true,
            'property' => $property,
            'accessible' => collect($sites)->contains(
                fn (array $site): bool => hash_equals($site['property'], (string) $property)
            ),
            'sites' => $sites,
        ];
    }

    public function configuredProperty(): ?string
    {
        $property = trim((string) config('services.google_search_console.property'));

        return $property === '' ? null : $property;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function searchAnalytics(array $payload): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Search Console no configurado.');
        }

        $configuration = config('services.google_search_console');
        $property = (string) $this->configuredProperty();
        $token = $this->tokens->accessToken('Search Console', $configuration);
        $url = rtrim((string) $configuration['search_analytics_url'], '/')
            .'/'.rawurlencode($property).'/searchAnalytics/query';
        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout((int) config('seo_analytics.http.connect_timeout', 5))
            ->timeout((int) config('seo_analytics.http.timeout', 20))
            ->post($url, $payload);

        if ($response->failed()) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                'Search Console Search Analytics',
                $response->status(),
                $response->json()
            ));
        }

        return $response->json() ?? [];
    }
}
