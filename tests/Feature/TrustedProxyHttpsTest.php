<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyHttpsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    public function test_trusted_reverse_proxy_preserves_https_for_all_commission_links_and_forms(): void
    {
        config()->set('app.trusted_proxies', ['10.20.0.10']);

        $response = $this->actingAsReportUser($this->admin())
            ->withServerVariables(['REMOTE_ADDR' => '10.20.0.10'])
            ->withHeaders([
                'Host' => 'laravel-informes-app',
                'X-Forwarded-Host' => 'informes.app.hrmotor.com',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/informes/comisiones-comerciales?month=2026-07&tab=summary');

        $response->assertOk();
        $html = html_entity_decode($response->getContent());

        foreach (['summary', 'delegations', 'call-center', 'contact-center', 'area-manager', 'financials'] as $tab) {
            $this->assertMatchesRegularExpression(
                '/href="https:\/\/informes\.app\.hrmotor\.com\/informes\/comisiones-comerciales\?[^\"]*tab='.preg_quote($tab, '/').'[^\"]*"/',
                $html
            );
        }

        $this->assertStringNotContainsString('http://informes.app.hrmotor.com', $html);
        $this->assertMatchesRegularExpression('/(?:href|action)="https:\/\/informes\.app\.hrmotor\.com\/informes\/comisiones-comerciales[^\"]*"/', $html);
    }

    public function test_forwarded_https_from_untrusted_origin_is_not_accepted(): void
    {
        config()->set('app.trusted_proxies', ['10.20.0.10']);
        Route::get('/_test/proxy-scheme', fn (Request $request): array => [
            'secure' => $request->isSecure(),
            'scheme' => $request->getScheme(),
            'url' => $request->fullUrl(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['Host' => 'informes.app.hrmotor.com', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_test/proxy-scheme')
            ->assertOk()
            ->assertJson([
                'secure' => false,
                'scheme' => 'http',
            ])
            ->assertJsonPath('url', fn (string $url): bool => str_starts_with($url, 'http://'));
    }

    public function test_trusted_proxy_generates_secure_closure_export_and_auditor_navigation_urls(): void
    {
        config()->set('app.trusted_proxies', ['10.20.0.10']);
        Route::get('/_test/commission-urls', fn (Request $request): array => [
            'audit' => $request->fullUrlWithQuery(['month' => '2026-07', 'audit_scope' => 'delegations']),
            'prepare' => route('reports.commercial-commissions.closure.prepare'),
            'approve' => route('reports.commercial-commissions.closure.approve'),
            'reopen' => route('reports.commercial-commissions.closure.reopen'),
            'export' => route('reports.commercial-commissions.export.commissions', ['month' => '2026-07']),
        ]);

        $urls = $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.10'])
            ->withHeaders([
                'Host' => 'laravel-informes-app',
                'X-Forwarded-Host' => 'informes.app.hrmotor.com',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->getJson('/_test/commission-urls')
            ->assertOk()
            ->json();

        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://informes.app.hrmotor.com/', $url);
        }
    }

    public function test_local_http_without_forwarded_headers_remains_http(): void
    {
        config()->set('app.trusted_proxies', ['10.20.0.10']);
        Route::get('/_test/local-scheme', fn (Request $request): array => [
            'scheme' => $request->getScheme(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/_test/local-scheme')
            ->assertOk()
            ->assertJson(['scheme' => 'http']);
    }

    private function admin(): ReportUser
    {
        return ReportUser::query()->create([
            'name' => 'Admin Proxy',
            'email' => 'admin-proxy@example.test',
            'password' => 'secret123',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function actingAsReportUser(ReportUser $user): static
    {
        config()->set('services.informes_auth.enabled', true);

        return $this->withSession([
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_email' => $user->email,
            'report_user_role' => $user->role,
        ]);
    }
}
