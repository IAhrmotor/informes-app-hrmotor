<?php

namespace App\Services\Salesforce;

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

        return $tokenData;
    }

    public function clearToken(): void
    {
        Cache::forget(config('salesforce.cache_key'));
    }

    private function requestClientCredentialsToken(): array
    {
        $response = Http::asForm()->post(config('salesforce.token_url'), [
            'grant_type' => 'client_credentials',
            'client_id' => config('salesforce.client_id'),
            'client_secret' => config('salesforce.client_secret'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Error autenticando Salesforce client_credentials: '.$response->status().' '.$response->body()
            );
        }

        $data = $response->json();

        $this->validateTokenResponse($data);

        return $data;
    }

    private function requestRefreshTokenToken(): array
    {
        $refreshToken = config('salesforce.refresh_token');

        if (blank($refreshToken)) {
            throw new RuntimeException(
                'Falta SALESFORCE_REFRESH_TOKEN. La credencial actual parece Authorization Code / Refresh Token, no Client Credentials puro.'
            );
        }

        $response = Http::asForm()->post(config('salesforce.token_url'), [
            'grant_type' => 'refresh_token',
            'client_id' => config('salesforce.client_id'),
            'client_secret' => config('salesforce.client_secret'),
            'refresh_token' => $refreshToken,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Error autenticando Salesforce refresh_token: '.$response->status().' '.$response->body()
            );
        }

        $data = $response->json();

        $this->validateTokenResponse($data);

        return $data;
    }

    private function validateTokenResponse(array $data): void
    {
        if (empty($data['access_token'])) {
            throw new RuntimeException('Salesforce no devolvió access_token: '.json_encode($data));
        }

        if (empty($data['instance_url'])) {
            throw new RuntimeException('Salesforce no devolvió instance_url: '.json_encode($data));
        }
    }
}
