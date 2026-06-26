<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_ver_gestion_de_usuarios_y_hacer_crud_basico(): void
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
            ->get('/informes/usuarios')
            ->assertOk()
            ->assertSee('Gestion de usuarios')
            ->assertSee('Crear usuario');

        $this->withSession($session)
            ->post('/informes/usuarios', [
                'name' => 'Director Uno',
                'email' => 'director1@hrmotor.com',
                'password' => 'secret12',
                'role' => ReportUser::ROLE_DIRECTOR,
                'is_active' => '1',
            ])
            ->assertRedirect('/informes/usuarios');

        $createdUser = ReportUser::query()->where('email', 'director1@hrmotor.com')->first();

        $this->assertNotNull($createdUser);
        $this->assertSame(ReportUser::ROLE_DIRECTOR, $createdUser->role);

        $this->withSession($session)
            ->put('/informes/usuarios/'.$createdUser->id, [
                'name' => 'Director Dos',
                'email' => 'director2@hrmotor.com',
                'password' => '',
                'role' => ReportUser::ROLE_VIEWER,
                'is_active' => '0',
            ])
            ->assertRedirect('/informes/usuarios');

        $this->assertDatabaseHas('report_users', [
            'id' => $createdUser->id,
            'name' => 'Director Dos',
            'email' => 'director2@hrmotor.com',
            'role' => ReportUser::ROLE_VIEWER,
            'is_active' => false,
        ]);

        $this->withSession($session)
            ->delete('/informes/usuarios/'.$createdUser->id)
            ->assertRedirect('/informes/usuarios');

        $this->assertDatabaseMissing('report_users', [
            'id' => $createdUser->id,
        ]);
    }

    public function test_director_no_puede_entrar_en_gestion_de_usuarios(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $this->withSession([
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_DIRECTOR,
            'report_user_email' => 'director@hrmotor.com',
        ])
            ->get('/informes/usuarios')
            ->assertRedirect('/informes/leads');
    }

    public function test_admin_no_puede_eliminar_su_propio_usuario(): void
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
            ->delete('/informes/usuarios/'.$admin->id)
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('report_users', [
            'id' => $admin->id,
        ]);
    }
}
