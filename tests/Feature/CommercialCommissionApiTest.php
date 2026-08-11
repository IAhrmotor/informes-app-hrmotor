<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class CommercialCommissionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_api_requiere_basic_auth(): void
    {
        config()->set('services.commissions_api.user', 'api-test-user');
        config()->set('services.commissions_api.password', 'api-test-password');

        $this->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertUnauthorized();
    }

    public function test_api_devuelve_comision_final_del_mes_en_curso_y_mes_anterior_cerrado(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-29 12:00:00'));
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        config()->set('services.commissions_api.user', 'api-user');
        config()->set('services.commissions_api.password', 'api-secret');

        SalesforceUser::create([
            'salesforce_id' => '005-API',
            'name' => 'Comercial API',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        foreach ([
            ['id' => 'API-MAY-1', 'date' => '2026-05-10'],
            ['id' => 'API-MAY-2', 'date' => '2026-05-12'],
            ['id' => 'API-JUN-1', 'date' => '2026-06-10'],
        ] as $row) {
            SalesforceOpportunity::create([
                'salesforce_id' => $row['id'],
                'name' => $row['id'],
                'owner_id' => '005-API',
                'owner_name' => 'Comercial API',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => $row['date'],
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 1000,
                'opo_div_descuento' => 100,
                'gestion_de_venta' => false,
                'vehicle_plate' => $row['id'],
                'vehicle_entry_date' => '2026-01-01',
            ]);
        }

        $auth = 'Basic '.base64_encode('api-user:api-secret');
        $service = app(CommercialCommissionDashboardService::class);
        $current = $service->finalCommissionForCommercial('005-API', '2026-06');
        $previous = $service->finalCommissionForCommercial('005-API', '2026-05');

        $this->withHeaders(['Authorization' => $auth])
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertOk()
            ->assertJson([
                'commercial_id' => '005-API',
                'current_month' => [
                    'month' => '2026-06',
                    'final_commission' => $current['final_commission'],
                ],
                'previous_closed_month' => [
                    'month' => '2026-05',
                    'final_commission' => $previous['final_commission'],
                ],
            ]);
    }

    public function test_api_admite_rotacion_y_revocacion_de_credenciales_versionadas(): void
    {
        config()->set('services.commissions_api.user', null);
        config()->set('services.commissions_api.password', null);
        config()->set('services.commissions_api.credentials', [
            [
                'integration' => 'erp_commissions',
                'credential_id' => '2026-08-current',
                'username' => 'erp-api',
                'password' => 'current-synthetic-secret',
            ],
            [
                'integration' => 'erp_commissions',
                'credential_id' => '2026-09-next',
                'username' => 'erp-api',
                'password' => 'next-synthetic-secret',
            ],
            [
                'integration' => 'revoked_consumer',
                'credential_id' => 'revoked',
                'username' => 'revoked-api',
                'password' => 'revoked-synthetic-secret',
                'revoked' => true,
            ],
        ]);

        $this->withBasicAuth('erp-api', 'current-synthetic-secret')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertOk();
        $this->withBasicAuth('erp-api', 'next-synthetic-secret')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertOk();
        $this->withBasicAuth('revoked-api', 'revoked-synthetic-secret')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertUnauthorized();
    }

    public function test_rate_limit_es_independiente_por_integracion(): void
    {
        config()->set('services.commissions_api.user', null);
        config()->set('services.commissions_api.password', null);
        config()->set('services.commissions_api.rate_limit_per_minute', 1);
        config()->set('services.commissions_api.credentials', [
            ['integration' => 'integration_a', 'credential_id' => 'a1', 'username' => 'api-a', 'password' => 'synthetic-a'],
            ['integration' => 'integration_b', 'credential_id' => 'b1', 'username' => 'api-b', 'password' => 'synthetic-b'],
        ]);

        $this->withBasicAuth('api-a', 'synthetic-a')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertOk();
        $this->withBasicAuth('api-a', 'synthetic-a')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertTooManyRequests();
        $this->withBasicAuth('api-b', 'synthetic-b')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertOk();
    }

    public function test_auditoria_api_no_registra_authorization_ni_secreto(): void
    {
        config()->set('services.commissions_api.user', 'audit-api');
        config()->set('services.commissions_api.password', 'audit-synthetic-secret');
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->withArgs(function (string $message, array $context): bool {
            $serialized = json_encode([$message, $context]);

            return $message === 'internal_api_request'
                && $context['integration'] === 'legacy_commissions_consumer'
                && $context['http_status'] === 200
                && filled($context['request_id'])
                && ! str_contains(mb_strtolower($serialized), 'authorization')
                && ! str_contains($serialized, 'audit-synthetic-secret');
        });
        Log::shouldReceive('channel')->once()->with('api_audit')->andReturn($logger);

        $this->withBasicAuth('audit-api', 'audit-synthetic-secret')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertOk()
            ->assertHeader('X-Request-ID');
    }
}
