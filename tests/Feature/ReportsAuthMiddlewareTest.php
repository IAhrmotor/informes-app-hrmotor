<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_informes_redirige_a_login_sin_popup_basic_auth_si_esta_activado(): void
    {
        config()->set('services.informes_auth.enabled', true);
        config()->set('services.informes_auth.email', 'admin@hrmotor.com');
        config()->set('services.informes_auth.password', 'secret');

        $response = $this->get('/informes/leads');

        $response
            ->assertRedirect('/login')
            ->assertSessionHas('url.intended', url('/informes/leads'));

        $this->assertFalse($response->headers->has('WWW-Authenticate'));
    }

    public function test_login_correcto_vuelve_a_url_solicitada_y_logout_limpia_sesion(): void
    {
        config()->set('services.informes_auth.enabled', true);
        config()->set('services.informes_auth.email', 'admin@hrmotor.com');
        config()->set('services.informes_auth.password', 'secret');

        $this->withSession(['url.intended' => url('/informes/leads')])
            ->post('/login', [
                'email' => 'admin@hrmotor.com',
                'password' => 'secret',
            ])
            ->assertRedirect('/informes/leads')
            ->assertSessionHas('informes_authenticated', true)
            ->assertSessionHas('informes_user', 'admin@hrmotor.com');

        $this->get('/login')->assertRedirect('/informes/campanas');

        $this->post('/logout')
            ->assertRedirect('/login')
            ->assertSessionMissing('informes_authenticated');
    }

    public function test_login_incorrecto_muestra_error_en_tarjeta(): void
    {
        config()->set('services.informes_auth.enabled', true);
        config()->set('services.informes_auth.email', 'admin@hrmotor.com');
        config()->set('services.informes_auth.password', 'secret');

        $this->from('/login')->post('/login', [
            'email' => 'admin@hrmotor.com',
            'password' => 'bad-password',
        ])
            ->assertSessionHasErrors('email')
            ->assertRedirect('/login');
    }

    public function test_informes_permite_acceso_si_auth_esta_desactivado(): void
    {
        config()->set('services.informes_auth.enabled', false);

        $this->get('/informes/campanas')->assertOk();
    }
}
