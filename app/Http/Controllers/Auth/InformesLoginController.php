<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class InformesLoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! config('services.informes_auth.enabled', true)
            || (bool) $request->session()->get('informes_authenticated', false)) {
            return redirect()->route('reports.campaigns.index');
        }

        return view('auth.informes-login');
    }

    public function login(Request $request): RedirectResponse
    {
        if (! config('services.informes_auth.enabled', true)) {
            return redirect()->intended(route('reports.campaigns.index'));
        }

        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! $this->credentialsAreValid($credentials['email'], $credentials['password'])) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Las credenciales no son correctas.']);
        }

        $request->session()->regenerate();
        $request->session()->put('informes_authenticated', true);
        $request->session()->put('informes_user', $credentials['email']);

        if ($request->boolean('remember')) {
            Cookie::queue(Cookie::make(
                'informes_remember',
                $this->rememberToken(),
                max((int) config('services.informes_auth.remember_days', 30), 1) * 24 * 60,
                null,
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            ));
        } else {
            Cookie::queue(Cookie::forget('informes_remember'));
        }

        return redirect()->intended(route('reports.campaigns.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['informes_authenticated', 'informes_user']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget('informes_remember'));

        return redirect()->route('login');
    }

    private function credentialsAreValid(string $login, string $password): bool
    {
        $configuredLogin = (string) config('services.informes_auth.email');
        $legacyUser = (string) config('services.informes_auth.user');
        $configuredPassword = (string) config('services.informes_auth.password');

        if ($configuredLogin === '' || $configuredPassword === '') {
            return false;
        }

        $loginOk = hash_equals($configuredLogin, $login)
            || ($legacyUser !== '' && hash_equals($legacyUser, $login));

        return $loginOk && hash_equals($configuredPassword, $password);
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
