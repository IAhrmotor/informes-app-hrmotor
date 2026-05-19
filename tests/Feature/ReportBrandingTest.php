<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReportBrandingTest extends TestCase
{
    public function test_leads_muestra_branding_hr_motor(): void
    {
        $response = $this->get('/informes/leads');

        $response->assertOk();
        $response->assertSee('Leads | HR Motor - Informes comerciales');
        $response->assertSee('HR Motor - Informes comerciales');
        $response->assertSee('/brand/logo-horizontal.svg', false);
        $response->assertSee('/brand/favicon.ico', false);
        $response->assertSee('/informes/reservas-ventas', false);
        $response->assertSee('alt="HR Motor"', false);
    }

    public function test_reservas_ventas_muestra_branding_hr_motor(): void
    {
        $response = $this->get('/informes/reservas-ventas');

        $response->assertOk();
        $response->assertSee('Reservas / Ventas | HR Motor - Informes comerciales');
        $response->assertSee('HR Motor - Informes comerciales');
        $response->assertSee('/brand/logo-horizontal.svg', false);
        $response->assertSee('/brand/favicon.ico', false);
        $response->assertSee('/informes/leads', false);
        $response->assertSee('alt="HR Motor"', false);
    }
}
