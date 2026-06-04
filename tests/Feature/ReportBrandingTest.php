<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReportBrandingTest extends TestCase
{
    public function test_leads_mantiene_titulo_favicon_y_switch_sin_brand_block(): void
    {
        $response = $this->get('/informes/leads');

        $response->assertOk();
        $response->assertSee('Leads | HR Motor - Informes comerciales');
        $response->assertSee('HR Motor - Informes comerciales');
        $response->assertSee('/brand/favicon.ico', false);
        $response->assertSee('/informes/reservas-ventas', false);
        $response->assertSee('class="header-actions"', false);
        $response->assertSee('class="report-switch"', false);
        $response->assertDontSee('class="brand-block"', false);
        $response->assertDontSee('/brand/logo-horizontal.svg', false);
        $response->assertDontSee('alt="HR Motor"', false);
    }

    public function test_reservas_ventas_mantiene_titulo_favicon_y_switch_sin_brand_block(): void
    {
        $response = $this->get('/informes/reservas-ventas');

        $response->assertOk();
        $response->assertSee('Reservas / Ventas | HR Motor - Informes comerciales');
        $response->assertSee('HR Motor - Informes comerciales');
        $response->assertSee('/brand/favicon.ico', false);
        $response->assertSee('/informes/leads', false);
        $response->assertSee('class="header-actions"', false);
        $response->assertSee('class="report-switch"', false);
        $response->assertDontSee('class="brand-block"', false);
        $response->assertDontSee('/brand/logo-horizontal.svg', false);
        $response->assertDontSee('alt="HR Motor"', false);
    }
}
