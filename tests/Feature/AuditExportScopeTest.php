<?php

namespace Tests\Feature;

use App\Models\MasterDelegation;
use App\Models\ReportUser;
use App\Models\SalesforceCall;
use App\Models\SalesforceOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditExportScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-25 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_area_manager_exporta_reservas_ventas_solo_de_su_zona(): void
    {
        $this->opportunity('006-north', 'Bilbao', '005-north');
        $this->opportunity('006-south', 'Alcobendas', '005-south');
        $manager = $this->reportUser(ReportUser::ROLE_AREA_MANAGER, ['area_zone' => 'north']);

        $ids = $this->csvIds(
            $this->withSession($this->sessionFor($manager))
                ->get('/informes/reservas-ventas/export/kpi-audit.csv?'.$this->reservationQuery())
                ->assertOk()
                ->streamedContent(),
            'Opportunity ID'
        );

        $this->assertSame(['006-north'], $ids);
    }

    public function test_responsable_exporta_llamadas_solo_de_su_delegacion(): void
    {
        $delegation = MasterDelegation::query()->create([
            'delegation_name' => 'Alcobendas',
            'commercial_group' => 'Madrid',
            'is_active' => true,
        ]);
        $this->createCall('00T-alcobendas', 'Alcobendas', 'Zona Sur y Centro', '005-a');
        $this->createCall('00T-alicante', 'Alicante', 'Zona Levante', '005-b');
        $manager = $this->reportUser(ReportUser::ROLE_DELEGATION_MANAGER, [
            'master_delegation_id' => $delegation->id,
        ]);

        $ids = $this->csvIds(
            $this->withSession($this->sessionFor($manager))
                ->get('/informes/llamadas/export/audit.csv?'.$this->callQuery())
                ->assertOk()
                ->streamedContent(),
            'task_id'
        );

        $this->assertSame(['00T-alcobendas'], $ids);
    }

    public function test_comercial_exporta_llamadas_solo_de_su_salesforce_user_id(): void
    {
        $this->createCall('00T-own', 'Alcobendas', 'Zona Sur y Centro', '005-own');
        $this->createCall('00T-other', 'Alcobendas', 'Zona Sur y Centro', '005-other');
        $commercial = $this->reportUser(ReportUser::ROLE_COMMERCIAL, [
            'salesforce_user_id' => '005-own',
        ]);

        $ids = $this->csvIds(
            $this->withSession($this->sessionFor($commercial))
                ->get('/informes/llamadas/export/audit.csv?'.$this->callQuery())
                ->assertOk()
                ->streamedContent(),
            'task_id'
        );

        $this->assertSame(['00T-own'], $ids);
    }

    public function test_viewer_no_puede_exportar_auditorias(): void
    {
        $viewer = $this->reportUser(ReportUser::ROLE_VIEWER);
        $session = $this->sessionFor($viewer);

        $this->withSession($session)
            ->get('/informes/llamadas/export/audit.csv?'.$this->callQuery())
            ->assertForbidden();
        $this->withSession($session)
            ->get('/informes/reservas-ventas/export/kpi-audit.csv?'.$this->reservationQuery())
            ->assertForbidden();
    }

    private function createCall(string $id, string $delegation, string $zone, string $userId): void
    {
        SalesforceCall::query()->create([
            'salesforce_id' => $id,
            'created_date' => '2026-05-20 10:00:00',
            'call_object' => 'call-object-'.$id,
            'included_in_dashboard' => true,
            'operational_user_id' => $userId,
            'operational_user_name' => $userId,
            'operational_team' => 'commercial',
            'owner_team' => 'commercial',
            'delegation' => $delegation,
            'zone' => $zone,
            'call_status' => 'answered',
            'is_answered' => true,
        ]);
    }

    private function opportunity(string $id, string $delegation, string $ownerId): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-05-20 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Reserva',
            'owner_id' => $ownerId,
            'owner_delegation' => $delegation,
            'reservation' => true,
            'cv_signed' => false,
        ]);
    }

    private function reportUser(string $role, array $overrides = []): ReportUser
    {
        return ReportUser::query()->create(array_merge([
            'name' => 'Audit '.$role,
            'email' => 'audit-'.$role.'-'.uniqid().'@example.test',
            'password' => Hash::make('audit-test-password'),
            'role' => $role,
            'is_active' => true,
        ], $overrides));
    }

    private function sessionFor(ReportUser $user): array
    {
        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
        ];
    }

    private function callQuery(): string
    {
        return http_build_query([
            'period' => 'custom',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ]);
    }

    private function reservationQuery(): string
    {
        return http_build_query([
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
            'metric' => 'oportunidades_totales',
        ]);
    }

    private function csvIds(string $content, string $idColumn): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($content))));
        $header = str_getcsv(array_shift($lines));
        $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        $idIndex = array_search($idColumn, $header, true);
        $ids = array_map(fn (string $line): string => str_getcsv($line)[$idIndex], $lines);
        sort($ids);

        return $ids;
    }
}
