<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionSnapshot;
use App\Models\CommercialFinancingPenalty;
use App\Models\CommercialFinancingPenaltyImport;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommissionMonthResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class CommercialCommissionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 12:00:00', config('app.timezone')));
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        config()->set('services.commissions_api.user', 'api-test-user');
        config()->set('services.commissions_api.password', 'api-test-password');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_api_requiere_basic_auth(): void
    {
        $this->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertUnauthorized();
    }

    public function test_api_rechaza_basic_auth_incorrecta(): void
    {
        $this->withBasicAuth('api-test-user', 'incorrect-synthetic-password')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertUnauthorized();
    }

    public function test_api_exige_salesforce_id_escalar(): void
    {
        $this->apiGet([])->assertUnprocessable()->assertJsonValidationErrors('salesforce_id');
        $this->apiGet(['salesforce_id' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('salesforce_id');
        $this->apiGet(['salesforce_id' => ['005-A', '005-B']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('salesforce_id');
    }

    public function test_api_devuelve_exactamente_summary_row_viva_del_mes_solicitado(): void
    {
        $this->commercial('005-API', 'Comercial API');
        $this->opportunity('API-JUN-1', '005-API', 'Comercial API', '2026-06-10');

        $expected = $this->canonicalRow('005-API', '2026-06');

        $response = $this->apiGet(['salesforce_id' => '005-API', 'month' => '2026-06'])
            ->assertOk()
            ->assertJsonPath('commercial_id', '005-API')
            ->assertJsonPath('month', '2026-06')
            ->assertJsonPath('economic_status', CommercialCommissionClosure::STATUS_PENDING_APPROVAL)
            ->assertJsonPath('has_data', true)
            ->assertJsonMissingPath('current_month')
            ->assertJsonMissingPath('previous_closed_month');

        $this->assertSame($expected, $response->json('row'));
        $this->assertArrayHasKey('details', $response->json('row'));
    }

    public function test_api_distingue_comercial_elegible_sin_datos_de_id_inexistente(): void
    {
        $this->commercial('005-WITHOUT-DATA', 'Sin datos');

        $this->apiGet(['salesforce_id' => '005-WITHOUT-DATA', 'month' => '2026-06'])
            ->assertOk()
            ->assertExactJson([
                'commercial_id' => '005-WITHOUT-DATA',
                'month' => '2026-06',
                'month_label' => app(CommissionMonthResolver::class)->resolve('2026-06')->translatedFormat('F Y'),
                'economic_status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
                'has_data' => false,
                'row' => null,
            ]);

        $this->apiGet(['salesforce_id' => '005-DOES-NOT-EXIST', 'month' => '2026-06'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Comercial no encontrado.']);
    }

    public function test_api_conserva_fila_real_cuando_la_comision_final_es_cero(): void
    {
        $this->commercial('005-ZERO', 'Comercial Cero');
        $import = CommercialFinancingPenaltyImport::query()->create([
            'original_filename' => 'synthetic-zero.xlsx',
            'rows_read' => 1,
            'rows_imported' => 1,
            'rows_unmatched' => 0,
            'commission_months' => ['2026-06'],
        ]);
        CommercialFinancingPenalty::query()->create([
            'import_id' => $import->id,
            'commission_month' => '2026-06-01',
            'commercial_name' => 'Comercial Cero',
            'salesforce_user_id' => '005-ZERO',
            'amount' => 0,
            'source_sheet' => 'Synthetic',
            'source_row' => 2,
            'is_active' => true,
        ]);

        $expected = $this->canonicalRow('005-ZERO', '2026-06');
        $this->assertSame(0.0, $expected['final_commission']);

        $response = $this->apiGet(['salesforce_id' => '005-ZERO', 'month' => '2026-06'])
            ->assertOk()
            ->assertJsonPath('has_data', true);

        $this->assertSame($expected, $response->json('row'));
        $this->assertSame(0.0, $response->json('row.final_commission'));
    }

    public function test_api_rechaza_usuarios_no_elegibles_y_tecnicos_con_404_generico(): void
    {
        $this->commercial('005-NON-COMMERCIAL', 'Usuario no comercial', 'System Administrator');
        $this->commercial('0052X00000AP4U5QAL', 'Usuario técnico');

        foreach (['005-NON-COMMERCIAL', '0052X00000AP4U5QAL'] as $salesforceId) {
            $this->apiGet(['salesforce_id' => $salesforceId, 'month' => '2026-06'])
                ->assertNotFound()
                ->assertExactJson(['message' => 'Comercial no encontrado.']);
        }
    }

    public function test_api_rechaza_usuario_inactivo_sin_fila_con_404_generico(): void
    {
        $commercial = $this->commercial('005-INACTIVE', 'Comercial Inactivo');
        $commercial->update(['is_active' => false]);

        $this->apiGet(['salesforce_id' => '005-INACTIVE', 'month' => '2026-06'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Comercial no encontrado.']);
    }

    public function test_api_exige_coincidencia_exacta_de_mayusculas_en_salesforce_id(): void
    {
        $this->commercial('005-CaseSensitive', 'Comercial Case Sensitive');

        $this->apiGet(['salesforce_id' => '005-casesensitive', 'month' => '2026-06'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Comercial no encontrado.']);
    }

    public function test_api_rechaza_month_invalido_o_futuro_sin_sustituirlo_por_mes_actual(): void
    {
        $this->commercial('005-API', 'Comercial API');

        foreach (['2026-00', '2026-13', '2026-06-01', 'junio-2026', '2026-09'] as $month) {
            $response = $this->apiGet(['salesforce_id' => '005-API', 'month' => $month])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('month');

            $this->assertNull($response->json('month'));
            $this->assertNull($response->json('row'));
        }
    }

    public function test_month_ausente_usa_resolver_y_conserva_compatibilidad_legacy_sin_recalcular_current(): void
    {
        $this->commercial('005-API', 'Comercial API');
        $this->opportunity('API-JUL-1', '005-API', 'Comercial API', '2026-07-10');
        $this->opportunity('API-AUG-1', '005-API', 'Comercial API', '2026-08-10');

        $resolved = app(CommissionMonthResolver::class)->resolve(null);
        $currentRow = $this->canonicalRow('005-API', $resolved->format('Y-m'));
        $previousRow = $this->canonicalRow('005-API', $resolved->subMonthNoOverflow()->format('Y-m'));

        $response = $this->apiGet(['salesforce_id' => '005-API'])
            ->assertOk()
            ->assertJsonPath('month', $resolved->format('Y-m'))
            ->assertJsonPath('has_data', true)
            ->assertJsonPath('current_month.month', '2026-08')
            ->assertJsonPath('previous_closed_month.month', '2026-07');

        $this->assertSame($currentRow, $response->json('row'));
        $this->assertSame($currentRow['final_commission'], $response->json('current_month.final_commission'));
        $this->assertSame($previousRow['final_commission'], $response->json('previous_closed_month.final_commission'));
    }

    public function test_month_explicito_construye_una_sola_vez_y_no_calcula_mes_anterior(): void
    {
        $row = [
            'commercial_id' => '005-API',
            'commercial_name' => 'Comercial API',
            'final_commission' => 123.45,
            'details' => ['operations' => [['opportunity_id' => 'SYNTHETIC-1']]],
        ];
        $dashboard = Mockery::mock(CommercialCommissionDashboardService::class);
        $dashboard->shouldNotReceive('hasEligibleCommercial');
        $dashboard->shouldReceive('build')
            ->once()
            ->with('2026-06', true, false, true)
            ->andReturn([
                'ready' => true,
                'month' => '2026-06',
                'month_label' => 'junio de 2026',
                'summary_rows' => [$row],
                'delegation_rows' => [],
            ]);
        $this->app->instance(CommercialCommissionDashboardService::class, $dashboard);

        $response = $this->apiGet(['salesforce_id' => '005-API', 'month' => '2026-06'])
            ->assertOk()
            ->assertJsonMissingPath('previous_closed_month');

        $this->assertSame($row, $response->json('row'));
    }

    public function test_dataset_vivo_no_disponible_devuelve_503_sin_exponer_issues(): void
    {
        $dashboard = Mockery::mock(CommercialCommissionDashboardService::class);
        $dashboard->shouldNotReceive('hasEligibleCommercial');
        $dashboard->shouldReceive('build')
            ->once()
            ->with('2026-06', true, false, true)
            ->andReturn([
                'ready' => false,
                'month' => '2026-06',
                'month_label' => 'junio de 2026',
                'economic_status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
                'issues' => ['Falta una tabla interna sensible.'],
                'summary_rows' => [],
                'delegation_rows' => [],
            ]);
        $this->app->instance(CommercialCommissionDashboardService::class, $dashboard);

        $this->apiGet(['salesforce_id' => '005-API', 'month' => '2026-06'])
            ->assertServiceUnavailable()
            ->assertExactJson([
                'message' => 'Las comisiones no están disponibles para el mes solicitado.',
            ])
            ->assertJsonMissingPath('issues')
            ->assertJsonMissingPath('has_data')
            ->assertJsonMissingPath('row');
    }

    public function test_compatibilidad_legacy_no_fabrica_ceros_si_un_periodo_no_esta_disponible(): void
    {
        $row = [
            'commercial_id' => '005-API',
            'commercial_name' => 'Comercial API',
            'final_commission' => 123.45,
            'details' => [],
        ];
        $dashboard = Mockery::mock(CommercialCommissionDashboardService::class);
        $dashboard->shouldNotReceive('hasEligibleCommercial');
        $dashboard->shouldReceive('build')
            ->once()
            ->with('2026-08', true, false, true)
            ->andReturn([
                'ready' => true,
                'month' => '2026-08',
                'month_label' => 'agosto de 2026',
                'economic_status' => CommercialCommissionClosure::STATUS_PROVISIONAL,
                'summary_rows' => [$row],
                'delegation_rows' => [],
            ]);
        $dashboard->shouldReceive('build')
            ->once()
            ->with('2026-07', true, false, true)
            ->andReturn([
                'ready' => false,
                'month' => '2026-07',
                'month_label' => 'julio de 2026',
                'issues' => ['Diagnóstico interno que no debe salir.'],
                'summary_rows' => [],
                'delegation_rows' => [],
            ]);
        $this->app->instance(CommercialCommissionDashboardService::class, $dashboard);

        $this->apiGet(['salesforce_id' => '005-API'])
            ->assertServiceUnavailable()
            ->assertExactJson([
                'message' => 'Las comisiones no están disponibles para el mes solicitado.',
            ])
            ->assertJsonMissingPath('current_month')
            ->assertJsonMissingPath('previous_closed_month');
    }

    public function test_api_aisla_la_fila_y_los_detalles_de_otro_comercial(): void
    {
        $this->commercial('005-A', 'Comercial A');
        $this->commercial('005-B', 'Comercial B');
        $this->opportunity('OPPORTUNITY-A-ONLY', '005-A', 'Comercial A', '2026-06-10');
        $this->opportunity('OPPORTUNITY-B-SECRET', '005-B', 'Comercial B', '2026-06-11', [
            'opo_for_importe_total' => 54321,
        ]);

        $response = $this->apiGet(['salesforce_id' => '005-A', 'month' => '2026-06'])
            ->assertOk();

        $this->assertSame($this->canonicalRow('005-A', '2026-06'), $response->json('row'));
        $serialized = $response->getContent();
        $this->assertStringNotContainsString('005-B', $serialized);
        $this->assertStringNotContainsString('Comercial B', $serialized);
        $this->assertStringNotContainsString('OPPORTUNITY-B-SECRET', $serialized);
        $this->assertStringNotContainsString('54321', $serialized);
    }

    public function test_api_no_elimina_detalles_legitimos_de_una_comision_compartida(): void
    {
        $this->commercial('005-OWNER', 'Comercial Owner');
        $this->commercial('005-SHARED', 'Comercial Shared');
        $this->opportunity('OPPORTUNITY-SHARED', '005-OWNER', 'Comercial Owner', '2026-06-10', [
            'shared_delivery_id' => '005-SHARED',
            'shared_delivery_name' => 'Comercial Shared',
        ]);

        $expected = $this->canonicalRow('005-SHARED', '2026-06');
        $response = $this->apiGet(['salesforce_id' => '005-SHARED', 'month' => '2026-06'])
            ->assertOk();

        $this->assertSame($expected, $response->json('row'));
        $this->assertSame('OPPORTUNITY-SHARED', $response->json('row.details.shared.0.opportunity_id'));
    }

    public function test_tasador_conserva_exactamente_modo_tramos_financiacion_rapidez_y_comision(): void
    {
        $this->commercial('005-APPRAISER', 'Tasador API', 'System Administrator', true);
        $this->opportunity('APPRAISAL-1', '005-APPRAISER', 'Tasador API', '2026-06-10', [
            'record_type_name' => 'Tasación',
            'beneficio_financiacion_comercial' => 300,
            'appraised_vehicle_id' => 'VEHICLE-APPRAISED-1',
            'appraised_vehicle_plate' => '0000TST',
        ]);

        $expected = $this->canonicalRow('005-APPRAISER', '2026-06');
        $response = $this->apiGet(['salesforce_id' => '005-APPRAISER', 'month' => '2026-06'])
            ->assertOk();

        $this->assertSame($expected, $response->json('row'));
        foreach ([
            'commission_mode',
            'appraiser_purchase_tier',
            'appraiser_purchase_rate',
            'appraiser_financing_commission',
            'appraiser_speed_amount',
            'final_commission',
        ] as $field) {
            $this->assertSame($expected[$field], $response->json('row.'.$field));
        }
    }

    public function test_mes_definitivo_prevalece_sobre_elegibilidad_actual_y_coincide_con_dashboard_web(): void
    {
        $commercial = $this->commercial('005-FROZEN', 'Comercial Congelado');
        $opportunity = $this->opportunity('FROZEN-1', '005-FROZEN', 'Comercial Congelado', '2026-06-10');
        $frozenDashboard = app(CommercialCommissionDashboardService::class)
            ->build('2026-06', true, false, true);
        $frozenRow = collect($frozenDashboard['summary_rows'])
            ->firstWhere('commercial_id', '005-FROZEN');

        $closure = CommercialCommissionClosure::query()->create([
            'month' => '2026-06',
            'closure_scope' => CommercialCommissionClosure::SCOPE_COMMERCIALS,
            'status' => CommercialCommissionClosure::STATUS_DEFINITIVE,
            'snapshot_version' => 1,
            'formula_version' => 'synthetic-test',
            'data_cutoff_at' => now(),
        ]);
        CommercialCommissionSnapshot::query()->create([
            'closure_id' => $closure->id,
            'month' => '2026-06',
            'version' => 1,
            'formula_version' => 'synthetic-test',
            'data_cutoff_at' => now(),
            'payload' => [
                'commercials' => $frozenDashboard,
                'formula_settings' => [],
                'review_audit' => [],
            ],
            'source_state' => ['synthetic' => true],
            'created_at' => now(),
        ]);

        $opportunity->update(['opo_div_descuento' => 9876]);
        $this->opportunity('LIVE-ONLY-CHANGE', '005-FROZEN', 'Comercial Congelado', '2026-06-11');
        $liveRow = $this->canonicalRow('005-FROZEN', '2026-06');
        $this->assertNotSame($frozenRow, $liveRow);

        $snapshot = app(CommercialCommissionClosureService::class)
            ->definitiveSnapshot('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS);
        $expectedFrozenRow = collect($snapshot['commercials']['summary_rows'])
            ->firstWhere('commercial_id', '005-FROZEN');

        $commercial->update([
            'is_active' => false,
            'profile_name' => 'System Administrator',
            'commission_appraiser' => false,
        ]);

        $this->get('/informes/comisiones-comerciales?month=2026-06&tab=summary')
            ->assertOk()
            ->assertViewHas('dashboard', function (array $dashboard) use ($expectedFrozenRow): bool {
                return collect($dashboard['summary_rows'])
                    ->firstWhere('commercial_id', '005-FROZEN') === $expectedFrozenRow;
            });

        $response = $this->apiGet(['salesforce_id' => '005-FROZEN', 'month' => '2026-06'])
            ->assertOk()
            ->assertJsonPath('economic_status', CommercialCommissionClosure::STATUS_DEFINITIVE);

        $this->assertSame($expectedFrozenRow, $response->json('row'));
        $this->assertNotSame($liveRow, $response->json('row'));

        $commercial->delete();

        $responseWithoutCurrentUser = $this->apiGet([
            'salesforce_id' => '005-FROZEN',
            'month' => '2026-06',
        ])->assertOk();

        $this->assertSame($expectedFrozenRow, $responseWithoutCurrentUser->json('row'));
    }

    public function test_mes_reabierto_ignora_snapshot_anterior_y_usa_fila_viva(): void
    {
        $this->commercial('005-REOPENED', 'Comercial Reabierto');
        $this->opportunity('REOPENED-1', '005-REOPENED', 'Comercial Reabierto', '2026-06-10');
        $liveRow = $this->canonicalRow('005-REOPENED', '2026-06');
        $frozenRow = [...$liveRow, 'final_commission' => 999999.0];
        $closure = CommercialCommissionClosure::query()->create([
            'month' => '2026-06',
            'closure_scope' => CommercialCommissionClosure::SCOPE_COMMERCIALS,
            'status' => CommercialCommissionClosure::STATUS_REOPENED,
            'snapshot_version' => 1,
        ]);
        CommercialCommissionSnapshot::query()->create([
            'closure_id' => $closure->id,
            'month' => '2026-06',
            'version' => 1,
            'formula_version' => 'synthetic-test',
            'data_cutoff_at' => now(),
            'payload' => ['commercials' => ['month' => '2026-06', 'summary_rows' => [$frozenRow]]],
            'created_at' => now(),
        ]);

        $response = $this->apiGet(['salesforce_id' => '005-REOPENED', 'month' => '2026-06'])
            ->assertOk()
            ->assertJsonPath('economic_status', CommercialCommissionClosure::STATUS_REOPENED);

        $this->assertSame($liveRow, $response->json('row'));
        $this->assertNotSame($frozenRow, $response->json('row'));
    }

    public function test_api_admite_rotacion_y_revocacion_de_credenciales_versionadas(): void
    {
        $this->commercial('005-API', 'Comercial API');
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
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API&month=2026-06')
            ->assertOk();
        $this->withBasicAuth('erp-api', 'next-synthetic-secret')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API&month=2026-06')
            ->assertOk();
        $this->withBasicAuth('revoked-api', 'revoked-synthetic-secret')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API&month=2026-06')
            ->assertUnauthorized();
    }

    public function test_rate_limit_es_independiente_por_integracion(): void
    {
        $this->commercial('005-API', 'Comercial API');
        config()->set('services.commissions_api.user', null);
        config()->set('services.commissions_api.password', null);
        config()->set('services.commissions_api.rate_limit_per_minute', 1);
        config()->set('services.commissions_api.credentials', [
            ['integration' => 'integration_a', 'credential_id' => 'a1', 'username' => 'api-a', 'password' => 'synthetic-a'],
            ['integration' => 'integration_b', 'credential_id' => 'b1', 'username' => 'api-b', 'password' => 'synthetic-b'],
        ]);

        $this->withBasicAuth('api-a', 'synthetic-a')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API&month=2026-06')
            ->assertOk();
        $this->withBasicAuth('api-a', 'synthetic-a')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API&month=2026-06')
            ->assertTooManyRequests();
        $this->withBasicAuth('api-b', 'synthetic-b')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API&month=2026-06')
            ->assertOk();
    }

    public function test_auditoria_api_no_registra_authorization_query_details_ni_secreto(): void
    {
        $this->commercial('005-AUDIT', 'Comercial Auditoría');
        $this->opportunity('AUDIT-DETAIL-SECRET', '005-AUDIT', 'Comercial Auditoría', '2026-06-10');
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
                && ! str_contains($serialized, 'audit-synthetic-secret')
                && ! str_contains($serialized, '005-AUDIT')
                && ! str_contains($serialized, 'AUDIT-DETAIL-SECRET')
                && ! str_contains($serialized, 'month=2026-06');
        });
        Log::shouldReceive('channel')->once()->with('api_audit')->andReturn($logger);

        $this->withBasicAuth('audit-api', 'audit-synthetic-secret')
            ->getJson('/api/comisiones_comercial?salesforce_id=005-AUDIT&month=2026-06')
            ->assertOk()
            ->assertHeader('X-Request-ID');
    }

    private function apiGet(array $query)
    {
        return $this->withBasicAuth('api-test-user', 'api-test-password')
            ->getJson('/api/comisiones_comercial'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    private function commercial(
        string $salesforceId,
        string $name,
        string $profile = 'Compra/Venta',
        bool $isAppraiser = false,
    ): SalesforceUser {
        return SalesforceUser::query()->create([
            'salesforce_id' => $salesforceId,
            'name' => $name,
            'profile_name' => $profile,
            'is_active' => true,
            'commission_appraiser' => $isAppraiser,
        ]);
    }

    private function opportunity(
        string $salesforceId,
        string $ownerId,
        string $ownerName,
        string $signedDate,
        array $overrides = [],
    ): SalesforceOpportunity {
        return SalesforceOpportunity::query()->create([
            ...[
                'salesforce_id' => $salesforceId,
                'name' => $salesforceId,
                'owner_id' => $ownerId,
                'owner_name' => $ownerName,
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => $signedDate,
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 1000,
                'opo_div_descuento' => 100,
                'gestion_de_venta' => false,
                'vehicle_plate' => $salesforceId,
                'vehicle_entry_date' => '2026-01-01',
            ],
            ...$overrides,
        ]);
    }

    private function canonicalRow(string $commercialId, string $month): array
    {
        $row = collect(app(CommercialCommissionDashboardService::class)
            ->build($month, true, false, true)['summary_rows'])
            ->firstWhere('commercial_id', $commercialId);

        $this->assertIsArray($row, "No se generó summary_row para {$commercialId} en {$month}.");

        return $row;
    }
}
