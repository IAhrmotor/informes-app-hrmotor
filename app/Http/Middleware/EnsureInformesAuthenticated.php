<?php

namespace App\Http\Middleware;

use App\Models\ReportUser;
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

        if ($rememberedUser = $this->rememberedReportUser($request)) {
            $request->session()->regenerate();
            $request->session()->put('informes_authenticated', true);
            $request->session()->put('informes_user', $rememberedUser->email);
            $request->session()->put('report_user_id', $rememberedUser->id);
            $request->session()->put('report_user_email', $rememberedUser->email);
            $request->session()->put('report_user_role', $rememberedUser->role);
            $rememberedUser->forceFill(['last_login_at' => now()])->save();

            return $next($request);
        }

        if ($this->hasValidRememberCookie($request)) {
            $request->session()->regenerate();
            $request->session()->put('informes_authenticated', true);
            $request->session()->put('informes_user', config('services.informes_auth.email'));
            $request->session()->put('report_user_id', null);
            $request->session()->put('report_user_email', config('services.informes_auth.email'));
            $request->session()->put('report_user_role', ReportUser::ROLE_ADMIN);

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

    private function rememberedReportUser(Request $request): ?ReportUser
    {
        $cookie = (string) $request->cookie('report_user_remember');

        if ($cookie === '' || ! str_contains($cookie, '|')) {
            return null;
        }

        [$id, $token] = explode('|', $cookie, 2);
        $user = ReportUser::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return null;
        }

        $expected = hash_hmac(
            'sha256',
            implode('|', [$user->id, $user->email, $user->password]),
            (string) config('app.key')
        );

        return hash_equals($expected, $token) ? $user : null;
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
