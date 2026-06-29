<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCommissionsApiBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) config('services.commissions_api.user', '');
        $password = (string) config('services.commissions_api.password', '');

        if (
            $request->getUser() !== $user
            || $request->getPassword() !== $password
        ) {
            return response('Unauthorized', 401, [
                'WWW-Authenticate' => 'Basic realm="Commercial Commissions API"',
            ]);
        }

        return $next($request);
    }
}
