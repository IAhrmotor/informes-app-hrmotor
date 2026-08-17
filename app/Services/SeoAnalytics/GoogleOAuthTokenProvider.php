<?php

namespace App\Services\SeoAnalytics;

use App\Support\IntegrationErrorSanitizer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleOAuthTokenProvider
{
    /** @var array<string, string> */
    private array $tokens = [];

    /** @param array<string, mixed> $configuration */
    public function accessToken(string $integration, array $configuration): string
    {
        if (isset($this->tokens[$integration])) {
            return $this->tokens[$integration];
        }

        $response = Http::asForm()
            ->connectTimeout((int) config('seo_analytics.http.connect_timeout', 5))
            ->timeout((int) config('seo_analytics.http.timeout', 20))
            ->post((string) $configuration['token_url'], [
                'client_id' => $configuration['client_id'],
                'client_secret' => $configuration['client_secret'],
                'refresh_token' => $configuration['refresh_token'],
                'grant_type' => 'refresh_token',
            ]);

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                $integration.' OAuth',
                $response->status(),
                $response->json()
            ));
        }

        return $this->tokens[$integration] = (string) $response->json('access_token');
    }
}
