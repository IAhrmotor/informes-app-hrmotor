<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditInternalApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $requestId = (string) Str::uuid();
        $status = 500;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } finally {
            Log::channel('api_audit')->info('internal_api_request', [
                'integration' => (string) $request->attributes->get('internal_api_integration', 'unidentified'),
                'credential_id' => (string) $request->attributes->get('internal_api_credential_id', 'unidentified'),
                'endpoint' => '/'.ltrim($request->path(), '/'),
                'method' => $request->method(),
                'occurred_at' => now()->toIso8601String(),
                'result' => $this->result($status),
                'http_status' => $status,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'request_id' => $requestId,
            ]);
        }
    }

    private function result(int $status): string
    {
        return match (true) {
            $status < 400 => 'success',
            $status < 500 => 'client_error',
            default => 'server_error',
        };
    }
}
