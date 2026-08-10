<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionClosureEvent;
use App\Models\CommercialCommissionSnapshot;
use App\Models\ReportUser;
use App\Services\Reports\AreaManagerCommissions\AreaManagerCommissionDashboardService;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommissionMonthResolver;
use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionDashboardService;
use App\Services\Reports\FinancialCommissions\FinancialCommissionDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionMonthClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_todas_las_pestanas_respetan_el_mismo_mes_incluido_el_actual(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');

        foreach (['2026-08', '2026-06', '2026-05'] as $month) {
            $resolved = app(CommissionMonthResolver::class)->resolve($month)->format('Y-m');
            $this->assertSame($month, $resolved);
            $this->assertSame($month, app(CommercialCommissionDashboardService::class)->build($month, false, false, false)['month']);
            $this->assertSame($month, app(CallCenterCommissionDashboardService::class)->build($month, includeDetails: false)['month']);
            $this->assertSame($month, app(ContactCenterCommissionDashboardService::class)->build($month, includeDetails: false)['month']);
            $this->assertSame($month, app(AreaManagerCommissionDashboardService::class)->build($month)['month']);
            $this->assertSame($month, app(FinancialCommissionDashboardService::class)->build($month)['month']);
        }
    }

    public function test_matriz_http_mantiene_mes_y_conciliacion_en_las_seis_pestanas(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');

        foreach (['summary', 'delegations', 'call-center', 'contact-center', 'area-manager', 'financials'] as $tab) {
            $this->get('/informes/comisiones-comerciales?month=2026-06&tab='.$tab)
                ->assertOk()
                ->assertSee('value="2026-06"', false)
                ->assertSee('Conciliacion del universo');
        }
    }

    public function test_mes_actual_es_provisional_y_no_puede_cerrarse(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $director = $this->user(ReportUser::ROLE_DIRECTOR);
        $this->assertSame('provisional', app(CommercialCommissionClosureService::class)->status('2026-08')['status']);

        $this->actingAsReportUser($director)
            ->post('/informes/comisiones-comerciales/closure/prepare', $this->closurePayload('2026-08'))
            ->assertSessionHasErrors('month');

        $this->assertDatabaseMissing('commercial_commission_closures', ['month' => '2026-08']);
    }

    public function test_direccion_prepara_y_aprueba_fotografia_definitiva_auditable(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $director = $this->user(ReportUser::ROLE_DIRECTOR);
        ReportUser::query()->create([
            'name' => 'Manager Norte',
            'email' => 'manager-norte@example.com',
            'password' => 'secret123',
            'role' => ReportUser::ROLE_AREA_MANAGER,
            'area_zone' => 'north',
            'is_active' => true,
        ]);

        $response = $this->actingAsReportUser($director)
            ->post('/informes/comisiones-comerciales/closure/prepare', $this->closurePayload('2026-06'));
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertNotSame('/informes/leads', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->assertDatabaseHas('commercial_commission_closures', [
            'month' => '2026-06',
            'status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
        ]);

        $this->actingAsReportUser($director)
            ->post('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-06', 'closure_scope' => CommercialCommissionClosure::SCOPE_COMMERCIALS])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('commercial_commission_closures', [
            'month' => '2026-06',
            'status' => CommercialCommissionClosure::STATUS_DEFINITIVE,
            'approved_by' => $director->id,
            'snapshot_version' => 1,
        ]);
        $this->assertSame(1, CommercialCommissionSnapshot::query()->where('month', '2026-06')->count());
        $this->assertSame(
            '2026-06',
            data_get(CommercialCommissionSnapshot::query()->where('month', '2026-06')->firstOrFail()->payload, 'commercials.month')
        );
        $this->assertSame(['prepared', 'approved'], CommercialCommissionClosureEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_solo_direccion_o_administrador_pueden_aprobar_y_reabrir(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $viewer = $this->user(ReportUser::ROLE_VIEWER);

        $this->actingAsReportUser($viewer)
            ->post('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-06'])
            ->assertRedirect();
        $this->actingAsReportUser($viewer)
            ->post('/informes/comisiones-comerciales/closure/reopen', ['month' => '2026-06', 'reason' => 'Corrección auditada'])
            ->assertRedirect();
    }

    public function test_los_cierres_de_comerciales_delegaciones_y_area_manager_son_independientes(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $director = $this->user(ReportUser::ROLE_DIRECTOR);
        $service = app(CommercialCommissionClosureService::class);
        $components = array_fill_keys(CommercialCommissionClosureService::REQUIRED_COMPONENTS, true);

        $service->prepare('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS, $components, $director);
        $service->approve('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS, $director);
        $service->prepare('2026-06', CommercialCommissionClosure::SCOPE_DELEGATIONS, $components, $director);

        $this->assertSame('definitive', $service->status('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS)['status']);
        $this->assertSame('pending_approval', $service->status('2026-06', CommercialCommissionClosure::SCOPE_DELEGATIONS)['status']);
        $this->assertSame('pending_approval', $service->status('2026-06', CommercialCommissionClosure::SCOPE_AREA_MANAGER)['status']);
        $this->assertSame(1, CommercialCommissionSnapshot::query()->where('month', '2026-06')->count());

        $service->reopen('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS, 'Corrección auditada de comerciales', $director);

        $this->assertSame('reopened', $service->status('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS)['status']);
        $this->assertSame('pending_approval', $service->status('2026-06', CommercialCommissionClosure::SCOPE_DELEGATIONS)['status']);
    }

    private function closurePayload(string $month): array
    {
        return [
            'month' => $month,
            'closure_scope' => CommercialCommissionClosure::SCOPE_COMMERCIALS,
            'components' => [
                'sales' => '1',
                'purchases' => '1',
                'cancellations' => '1',
                'reviews' => '1',
                'adjustments' => '1',
            ],
        ];
    }

    private function user(string $role): ReportUser
    {
        return ReportUser::query()->create([
            'name' => ucfirst($role),
            'email' => $role.'@example.com',
            'password' => 'secret123',
            'role' => $role,
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
