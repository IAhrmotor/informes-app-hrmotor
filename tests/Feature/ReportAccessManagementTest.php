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
}
