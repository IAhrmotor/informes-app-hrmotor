<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardUiPhaseOneTest extends TestCase
{
    public function test_html_inicial_muestra_carga_y_solo_filtros_fase_uno(): void
    {
        $response = $this->get('/informes/leads');

        $response->assertOk();
        $response->assertSee('Cargando datos de Salesforce...');
        $response->assertSee('leadDelegation');
        $response->assertSee('Tipo de Lead');
        $response->assertSee('Tasación');
        $response->assertSee('Venta');
        $response->assertSee('commercialDelegation');
        $response->assertSee('Zona', false);
        $response->assertSee('Portal');
        $response->assertSee('Comercial');
        $response->assertSee('Exposición');
        $response->assertSee('Comerciales/Delegaciones/Zonas');
        $response->assertSee('Delegaciones por reparto de leads');
        $response->assertSee('Orden de cuadros');
        $response->assertDontSee('id="channel"', false);
        $response->assertDontSee('id="status"', false);
        $response->assertDontSee('leadGroup');
        $response->assertDontSee('Grupo del lead');
        $response->assertDontSee('Venta con cambio');
        $response->assertDontSee('Datos actualizados: -');
        $response->assertDontSee('Grupo portal');
        $response->assertDontSee('Solo Exposicion', false);
        $response->assertDontSee('Informe mensual');
        $response->assertDontSee('KPIs clave');
        $response->assertDontSee('Detalle portal');
        $response->assertDontSee('Calidad de dato');
        $response->assertDontSee('data-panel="panel-comerciales">Comerciales</button>', false);
        $response->assertDontSee('Delegaciones / Zonas');
    }

    public function test_js_formatea_fecha_europea_y_no_expone_grupo_portal(): void
    {
        $js = file_get_contents(resource_path('js/reports/leads-dashboard.js'));

        $this->assertStringContainsString("Intl.DateTimeFormat('es-ES'", $js);
        $this->assertStringContainsString("day: '2-digit'", $js);
        $this->assertStringContainsString("month: '2-digit'", $js);
        $this->assertStringContainsString('makeTableSortable', $js);
        $this->assertStringContainsString('parseSortableValue', $js);
        $this->assertStringContainsString("'leadType'", $js);
        $this->assertStringContainsString("lead_type", $js);
        $this->assertStringContainsString('formatCountPercent', $js);
        $this->assertStringContainsString('data-sort-value', $js);
        $this->assertStringNotContainsString("'leadGroup'", $js);
        $this->assertStringNotContainsString("'channel'", $js);
        $this->assertStringNotContainsString("'status'", $js);
        $this->assertStringNotContainsString('portalGroup', $js);
        $this->assertStringNotContainsString('portal_group', $js);
    }
}
