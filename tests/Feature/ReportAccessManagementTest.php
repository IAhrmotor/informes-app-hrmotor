<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_actualizar_el_rol_minimo_por_informe(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $admin = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($session)
            ->get('/informes/permisos-informes')
            ->assertOk()
            ->assertSee('Permisos por informe');

        $this->withSession($session)
            ->put('/informes/permisos-informes', [
                'minimum_roles' => [
                    'leads' => ReportUser::ROLE_DIRECTOR,
                    'reservations-sales' => ReportUser::ROLE_VIEWER,
                    'calls' => ReportUser::ROLE_VIEWER,
                    'campaigns' => ReportUser::ROLE_DIRECTOR,
                    'commercial-commissions' => ReportUser::ROLE_ADMIN,
                    'stock' => ReportUser::ROLE_ADMIN,
                ],
            ])
            ->assertRedirect('/informes/permisos-informes');

        $this->assertDatabaseHas('report_access_settings', [
            'report_key' => 'leads',
            'minimum_role' => ReportUser::ROLE_DIRECTOR,
        ]);
        $this->assertDatabaseHas('report_access_settings', [
            'report_key' => 'commercial-commissions',
            'minimum_role' => ReportUser::ROLE_ADMIN,
        ]);
    }

    public function test_usuario_sin_permiso_en_un_informe_es_redirigido_al_siguiente_disponible(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $admin = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $viewer = ReportUser::query()->create([
            'name' => 'Viewer',
            'email' => 'viewer@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_VIEWER,
            'is_active' => true,
        ]);

        $adminSession = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($adminSession)
            ->put('/informes/permisos-informes', [
                'minimum_roles' => [
                    'leads' => ReportUser::ROLE_DIRECTOR,
                    'reservations-sales' => ReportUser::ROLE_VIEWER,
                    'calls' => ReportUser::ROLE_VIEWER,
                    'campaigns' => ReportUser::ROLE_DIRECTOR,
                    'commercial-commissions' => ReportUser::ROLE_DIRECTOR,
                    'stock' => ReportUser::ROLE_ADMIN,
                ],
            ])
            ->assertRedirect('/informes/permisos-informes');

        $viewerSession = [
            'informes_authenticated' => true,
            'report_user_id' => $viewer->id,
            'report_user_role' => ReportUser::ROLE_VIEWER,
            'report_user_email' => $viewer->email,
        ];

        $this->withSession($viewerSession)
            ->get('/informes/leads')
            ->assertRedirect('/informes/reservas-ventas');
    }

    public function test_auditor_de_comisiones_solo_puede_abrir_el_informe_de_comisiones(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $auditor = ReportUser::query()->create([
            'name' => 'Auditor de Comisiones',
            'email' => 'auditor.comisiones@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_COMMISSION_AUDITOR,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $auditor->id,
            'report_user_role' => ReportUser::ROLE_COMMISSION_AUDITOR,
            'report_user_email' => $auditor->email,
        ];

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales')
            ->assertOk()
            ->assertSee('Comisiones');

        $this->withSession($session)
            ->get('/informes/leads')
            ->assertRedirect('/informes/comisiones-comerciales');

        $this->withSession($session)
            ->get('/informes/permisos-informes')
            ->assertRedirect('/informes');
    }

    public function test_area_manager_accede_a_informes_operativos_y_solo_ve_su_area_en_comisiones(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $manager = ReportUser::query()->create([
            'name' => 'Manager Norte',
            'email' => 'manager.area@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_AREA_MANAGER,
            'area_zone' => 'north',
            'is_active' => true,
        ]);
        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $manager->id,
            'report_user_role' => ReportUser::ROLE_AREA_MANAGER,
            'report_user_email' => $manager->email,
        ];

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales?tab=financials')
            ->assertOk()
            ->assertDontSee('>Financieros<', false)
            ->assertDontSee('Vista restringida')
            ->assertDontSee('Buscar manager')
            ->assertDontSee('>Estado<', false)
            ->assertDontSee('Comisión Oscar');

        $this->withSession($session)
            ->get('/informes/leads')
            ->assertOk();

        $this->withSession($session)
            ->get('/informes/reservas-ventas')
            ->assertOk();

        $this->withSession($session)
            ->get('/informes/llamadas')
            ->assertOk();
    }
}
