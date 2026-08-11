<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class EnsureCommissionsApiBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $credentials = $this->credentials();

        if ($credentials === []) {
            return response('Service unavailable', 503);
        }

        $suppliedUser = (string) $request->getUser();
        $suppliedPassword = (string) $request->getPassword();
        $knownIntegration = null;
        $matchedCredential = null;

        foreach ($credentials as $credential) {
            $userMatches = $this->secureEquals($credential['username'], $suppliedUser);

            if ($userMatches) {
                $knownIntegration ??= $credential['integration'];
            }

            $passwordMatches = $this->secureEquals($credential['password'], $suppliedPassword);

            if ($userMatches && $passwordMatches && $matchedCredential === null) {
                $matchedCredential = $credential;
            }
        }

        if ($knownIntegration !== null) {
            $request->attributes->set('internal_api_integration', $knownIntegration);
        }

        $authThrottleKey = $this->authThrottleKey($request, $knownIntegration, $suppliedUser);
        $maxFailures = max(1, (int) config('services.commissions_api.auth_failures_per_minute', 10));

        if (RateLimiter::tooManyAttempts($authThrottleKey, $maxFailures)) {
            return response('Too Many Requests', 429, [
                'Retry-After' => (string) max(1, RateLimiter::availableIn($authThrottleKey)),
            ]);
        }

        if ($matchedCredential === null) {
            RateLimiter::hit($authThrottleKey, 60);

            return response('Unauthorized', 401, [
                'WWW-Authenticate' => 'Basic realm="Commercial Commissions API"',
            ]);
        }

        RateLimiter::clear($authThrottleKey);
        $request->attributes->set('internal_api_integration', $matchedCredential['integration']);
        $request->attributes->set('internal_api_credential_id', $matchedCredential['credential_id']);

        return $next($request);
    }

    /**
     * @return array<int, array{integration: string, credential_id: string, username: string, password: string}>
     */
    private function credentials(): array
    {
        $credentials = [];

        foreach ((array) config('services.commissions_api.credentials', []) as $candidate) {
            if (! is_array($candidate) || (bool) ($candidate['revoked'] ?? false)) {
                continue;
            }

            $credential = [
                'integration' => trim((string) ($candidate['integration'] ?? '')),
                'credential_id' => trim((string) ($candidate['credential_id'] ?? '')),
                'username' => trim((string) ($candidate['username'] ?? '')),
                'password' => (string) ($candidate['password'] ?? ''),
            ];

            if ($this->validCredential($credential)) {
                $credentials[] = $credential;
            }
        }

        $legacy = [
            'integration' => 'legacy_commissions_consumer',
            'credential_id' => 'legacy',
            'username' => trim((string) config('services.commissions_api.user', '')),
            'password' => (string) config('services.commissions_api.password', ''),
        ];

        if ($this->validCredential($legacy)) {
            $credentials[] = $legacy;
        }

        return $credentials;
    }

    private function validCredential(array $credential): bool
    {
        foreach (['integration', 'credential_id'] as $field) {
            if (! preg_match('/\A[a-zA-Z0-9._@-]{1,100}\z/', $credential[$field])) {
                return false;
            }
        }

        return preg_match('/\A[^\x00-\x1F\x7F:]{1,255}\z/u', $credential['username']) === 1
            && $credential['password'] !== '';
    }

    private function secureEquals(string $expected, string $supplied): bool
    {
        return hash_equals(hash('sha256', $expected), hash('sha256', $supplied));
    }

    private function authThrottleKey(Request $request, ?string $integration, string $suppliedUser): string
    {
        $identity = $integration === null
            ? 'unknown|'.$suppliedUser.'|'.(string) $request->ip()
            : 'known|'.$integration;

        return 'internal-api-auth:'.hash('sha256', $identity);
    }
}
