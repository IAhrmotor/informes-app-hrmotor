<?php

namespace App\Services\SeoAnalytics;

use App\Support\IntegrationErrorSanitizer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SistrixClient
{
    public function configured(): bool
    {
        return filled(config('services.sistrix.api_key'));
    }

    /** @return array{configured: bool, api_accessible: ?bool, ai_check: string} */
    public function diagnose(): array
    {
        if (! $this->configured()) {
            return ['configured' => false, 'api_accessible' => null, 'ai_check' => 'pending'];
        }

        $response = Http::asForm()
            ->acceptJson()
            ->connectTimeout((int) config('seo_analytics.http.connect_timeout', 5))
            ->timeout((int) config('seo_analytics.http.timeout', 20))
            ->post((string) config('services.sistrix.credits_url'), [
                'api_key' => config('services.sistrix.api_key'),
                'format' => 'json',
            ]);

        $payload = $response->json();

        if ($response->failed()
            || ! is_array($payload)
            || $payload === []
            || filled(data_get($payload, 'error'))) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                'SISTRIX API',
                $response->status(),
                $payload
            ));
        }

        return ['configured' => true, 'api_accessible' => true, 'ai_check' => 'pending'];
    }
}
