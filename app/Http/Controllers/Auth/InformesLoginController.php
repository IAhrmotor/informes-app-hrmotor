<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ReportUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
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

        $reportUser = $this->reportUserForCredentials($credentials['email'], $credentials['password']);
        $legacyLogin = $reportUser === null && $this->legacyCredentialsAreValid($credentials['email'], $credentials['password']);

        if ($reportUser === null && ! $legacyLogin) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Las credenciales no son correctas.']);
        }

        $request->session()->regenerate();
        $this->storeAuthenticatedSession($request, $credentials['email'], $reportUser);

        if ($request->boolean('remember')) {
            $this->queueRememberCookie($request, $reportUser);
        } else {
            Cookie::queue(Cookie::forget('informes_remember'));
            Cookie::queue(Cookie::forget('report_user_remember'));
        }

        return redirect()->intended(route('reports.campaigns.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget([
            'informes_authenticated',
            'informes_user',
            'report_user_id',
            'report_user_email',
            'report_user_role',
        ]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget('informes_remember'));
        Cookie::queue(Cookie::forget('report_user_remember'));

        return redirect()->route('login');
    }

    private function reportUserForCredentials(string $login, string $password): ?ReportUser
    {
        $user = ReportUser::query()
            ->where('email', $login)
            ->where('is_active', true)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    private function legacyCredentialsAreValid(string $login, string $password): bool
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

    private function storeAuthenticatedSession(Request $request, string $fallbackEmail, ?ReportUser $user): void
    {
        $role = $user?->role ?: ReportUser::ROLE_ADMIN;
        $email = $user?->email ?: $fallbackEmail;

        $request->session()->put('informes_authenticated', true);
        $request->session()->put('informes_user', $email);
        $request->session()->put('report_user_id', $user?->id);
        $request->session()->put('report_user_email', $email);
        $request->session()->put('report_user_role', $role);
    }

    private function queueRememberCookie(Request $request, ?ReportUser $user): void
    {
        $minutes = max((int) config('services.informes_auth.remember_days', 30), 1) * 24 * 60;

        if ($user) {
            Cookie::queue(Cookie::make(
                'report_user_remember',
                $this->reportUserRememberToken($user),
                $minutes,
                null,
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            ));
            Cookie::queue(Cookie::forget('informes_remember'));

            return;
        }

        Cookie::queue(Cookie::make(
            'informes_remember',
            $this->rememberToken(),
            $minutes,
            null,
            null,
            $request->isSecure(),
            true,
            false,
            'lax'
        ));
        Cookie::queue(Cookie::forget('report_user_remember'));
    }

    private function reportUserRememberToken(ReportUser $user): string
    {
        return $user->id.'|'.hash_hmac(
            'sha256',
            implode('|', [$user->id, $user->email, $user->password]),
            (string) config('app.key')
        );
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
