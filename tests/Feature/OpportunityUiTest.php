<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpportunityUiTest extends TestCase
{
    public function test_existe_dashboard_reservas_ventas_con_tabs_y_filtros(): void
    {
        $response = $this->get('/informes/reservas-ventas');

        $response->assertOk();
        $response->assertSee('Reservas / Ventas');
        $response->assertSee('/informes/leads', false);
        $response->assertSee('Resumen direccion');
        $response->assertSee('Comerciales / delegaciones / zonas');
        $response->assertSee('Portales / procedencia');
        $response->assertSee('Criterio de fecha');
        $response->assertSee('Fecha de creacion');
        $response->assertSee('Fecha de reserva');
        $response->assertSee('Fecha de firma contrato');
        $response->assertSee('Tipo de oportunidad');
        $response->assertDontSee('Opportunity');
    }

    public function test_leads_tiene_navegacion_a_reservas_ventas(): void
    {
        $response = $this->get('/informes/leads');

        $response->assertOk();
        $response->assertSee('Reservas / Ventas');
        $response->assertSee('/informes/reservas-ventas', false);
    }
}
