<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInformesAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.informes_auth.enabled', true)) {
            return $next($request);
        }

        if ((bool) $request->session()->get('informes_authenticated', false)) {
            return $next($request);
        }

        if ($this->hasValidRememberCookie($request)) {
            $request->session()->regenerate();
            $request->session()->put('informes_authenticated', true);
            $request->session()->put('informes_user', config('services.informes_auth.email'));

            return $next($request);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('login');
    }

    private function hasValidRememberCookie(Request $request): bool
    {
        $cookie = (string) $request->cookie('informes_remember');

        return $cookie !== '' && hash_equals($this->rememberToken(), $cookie);
    }

    private function rememberToken(): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                (string) config('services.informes_auth.email'),
                (string) config('services.informes_auth.password'),
            ]),
            (string) config('app.key')
        );
    }
}
