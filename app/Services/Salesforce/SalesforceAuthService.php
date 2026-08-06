<?php

namespace App\Services\Salesforce;

use App\Support\IntegrationErrorSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SalesforceAuthService
{
    public function accessToken(): array
    {
        $cacheKey = config('salesforce.cache_key');

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && ! empty($cached['access_token']) && ! empty($cached['instance_url'])) {
            return $cached;
        }

        $mode = config('salesforce.auth_mode');

        $tokenData = match ($mode) {
            'client_credentials' => $this->requestClientCredentialsToken(),
            'refresh_token' => $this->requestRefreshTokenToken(),
            default => throw new RuntimeException("Modo OAuth Salesforce no soportado: {$mode}"),
        };

        $expiresIn = (int) ($tokenData['expires_in'] ?? 1800);

        Cache::put($cacheKey, $tokenData, now()->addSeconds(max($expiresIn - 120, 300)));
        Cache::put(config('salesforce.instance_url_cache_key'), $tokenData['instance_url'], now()->addSeconds(max($expiresIn - 120, 300)));

        return $tokenData;
    }

    public function clearToken(): void
    {
        Cache::forget(config('salesforce.cache_key'));
        Cache::forget(config('salesforce.instance_url_cache_key'));
    }

    private function requestClientCredentialsToken(): array
    {
        $this->assertConfigured([
            'token_url' => config('salesforce.token_url'),
            'client_id' => config('salesforce.client_id'),
            'client_secret' => config('salesforce.client_secret'),
        ], 'client_credentials');

        $response = Http::asForm()->post(config('salesforce.token_url'), [
            'grant_type' => 'client_credentials',
            'client_id' => config('salesforce.client_id'),
            'client_secret' => config('salesforce.client_secret'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                'Salesforce OAuth client_credentials',
                $response->status(),
                $response->json()
            ));
        }

        $data = $response->json();

        $this->validateTokenResponse($data);

        return $data;
    }

    private function requestRefreshTokenToken(): array
    {
        $refreshToken = config('salesforce.refresh_token');

        $this->assertConfigured([
            'token_url' => config('salesforce.token_url'),
            'client_id' => config('salesforce.client_id'),
            'client_secret' => config('salesforce.client_secret'),
            'refresh_token' => $refreshToken,
        ], 'refresh_token');

        $response = Http::asForm()->post(config('salesforce.token_url'), [
            'grant_type' => 'refresh_token',
            'client_id' => config('salesforce.client_id'),
            'client_secret' => config('salesforce.client_secret'),
            'refresh_token' => $refreshToken,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(IntegrationErrorSanitizer::remoteFailure(
                'Salesforce OAuth refresh_token',
                $response->status(),
                $response->json()
            ));
        }

        $data = $response->json() ?? [];

        $this->validateTokenResponse($data);

        return $data;
    }

    private function validateTokenResponse(array $data): void
    {
        if (empty($data['access_token'])) {
            throw new RuntimeException('Salesforce OAuth devolvió una respuesta sin token de acceso.');
        }

        if (empty($data['instance_url'])) {
            throw new RuntimeException('Salesforce OAuth devolvió una respuesta sin URL de instancia.');
        }
    }

    private function assertConfigured(array $configuration, string $mode): void
    {
        foreach ($configuration as $value) {
            if (blank($value)) {
                throw new RuntimeException("Salesforce OAuth no configurado para el modo {$mode}.");
            }
        }
    }
}
