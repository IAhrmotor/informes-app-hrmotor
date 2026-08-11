<?php

namespace Tests\Feature;

use App\Services\Campaigns\MetaAdsClient;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDelegationReviewsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class CredentialSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuracion_no_contiene_fallbacks_de_credenciales_internas(): void
    {
        $source = file_get_contents(config_path('services.php'));

        foreach ([
            'INTERNAL_REVIEWS_ENDPOINT',
            'INTERNAL_REVIEWS_USER',
            'INTERNAL_REVIEWS_PASSWORD',
            'COMMISSIONS_API_USER',
            'COMMISSIONS_API_PASSWORD',
            'COMMISSIONS_API_CREDENTIALS',
        ] as $variable) {
            $this->assertMatchesRegularExpression(
                "/env\\('{$variable}'\\)/",
                $source
            );
            $this->assertDoesNotMatchRegularExpression(
                "/env\\('{$variable}'\\s*,/",
                $source
            );
        }

        $this->assertStringNotContainsString('INFORMES_AUTH_', $source);
    }

    public function test_api_de_comisiones_sin_credenciales_falla_cerrada_como_no_configurada(): void
    {
        config()->set('services.commissions_api.user', null);
        config()->set('services.commissions_api.password', null);
        config()->set('services.commissions_api.credentials', []);

        $this->getJson('/api/comisiones_comercial?salesforce_id=005-TEST')
            ->assertServiceUnavailable()
            ->assertHeaderMissing('WWW-Authenticate');
    }

    public function test_reseñas_no_configuradas_no_generan_authorization_y_conservan_estado_tecnico(): void
    {
        config()->set('services.internal_reviews.endpoint', null);
        config()->set('services.internal_reviews.user', null);
        config()->set('services.internal_reviews.password', null);
        Http::fake();

        $result = app(CommercialCommissionDelegationReviewsService::class)
            ->forMonthAndDelegations(CarbonImmutable::parse('2026-06-01'), collect(['Alicante']));

        Http::assertNothingSent();
        $this->assertSame(0, $result['Alicante']['reviews_count']);
        $this->assertNull($result['Alicante']['average_rating']);
        $this->assertSame('not_configured', $result['Alicante']['technical_status']);
    }

    public function test_fallo_remoto_de_reseñas_no_rompe_el_informe_ni_expone_secretos_en_logs(): void
    {
        $testPassword = 'reviews-test-password';
        config()->set('services.internal_reviews.endpoint', 'https://reviews.example.test/metrics');
        config()->set('services.internal_reviews.user', 'reviews-test-user');
        config()->set('services.internal_reviews.password', $testPassword);
        Http::fake([
            'reviews.example.test/*' => Http::response([
                'error' => 'remote_failure',
                'password' => $testPassword,
                'authorization' => 'Basic test-value',
            ], 502),
        ]);
        Log::spy();

        $result = app(CommercialCommissionDelegationReviewsService::class)
            ->forMonthAndDelegations(CarbonImmutable::parse('2026-06-01'), collect(['Alicante']));

        $this->assertSame(0, $result['Alicante']['reviews_count']);
        $this->assertSame('remote_error', $result['Alicante']['technical_status']);
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($testPassword): bool {
            $serialized = json_encode([$message, $context]);

            return str_contains($message, 'Respuesta no satisfactoria')
                && ! str_contains($serialized, $testPassword)
                && ! str_contains(mb_strtolower($serialized), 'authorization');
        });
    }

    public function test_error_de_meta_no_incluye_token_password_ni_authorization(): void
    {
        $testToken = 'meta-test-access-token';
        config()->set('services.meta_ads.access_token', $testToken);
        config()->set('services.meta_ads.ad_account_ids', ['123456']);
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'type' => 'OAuthException',
                    'access_token' => $testToken,
                    'password' => 'remote-test-password',
                    'authorization' => 'Bearer remote-test-token',
                ],
            ], 401),
        ]);
        Log::spy();

        try {
            app(MetaAdsClient::class)->insights(
                '123456',
                CarbonImmutable::parse('2026-06-01'),
                CarbonImmutable::parse('2026-06-03')
            );
            $this->fail('Se esperaba una excepción remota controlada.');
        } catch (RuntimeException $exception) {
            $message = mb_strtolower($exception->getMessage());
            $this->assertStringNotContainsString(mb_strtolower($testToken), $message);
            $this->assertStringNotContainsString('remote-test-password', $message);
            $this->assertStringNotContainsString('bearer remote-test-token', $message);
            $this->assertStringContainsString('http 401', $message);
        }

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) use ($testToken): bool {
            $serialized = mb_strtolower(json_encode([$message, $context]));

            return ! str_contains($serialized, mb_strtolower($testToken))
                && ! str_contains($serialized, 'authorization')
                && ! str_contains($serialized, 'password');
        });
    }
}
