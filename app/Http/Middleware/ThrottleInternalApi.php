<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleInternalApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $integration = (string) $request->attributes->get('internal_api_integration', '');

        if ($integration === '') {
            return response('Unauthorized', 401);
        }

        $limit = max(1, (int) config('services.commissions_api.rate_limit_per_minute', 120));
        $key = 'internal-api-requests:'.hash('sha256', $integration);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response('Too Many Requests', 429, [
                'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, 60);
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set(
            'X-RateLimit-Remaining',
            (string) max(0, $limit - RateLimiter::attempts($key)),
        );

        return $response;
    }
}
