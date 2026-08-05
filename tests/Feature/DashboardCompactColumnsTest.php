<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardCompactColumnsTest extends TestCase
{
    public function test_tablas_compactan_porcentajes_en_la_misma_celda(): void
    {
        $blade = file_get_contents(resource_path('views/reports/leads/index.blade.php'));
        $js = file_get_contents(resource_path('js/reports/leads-dashboard.js'));

        $this->assertStringContainsString('formatCountPercent(row.convertidos, row.conversion_pct)', $js);
        $this->assertStringContainsString('formatCountPercent(row.descartados, row.descarte_pct)', $js);
        $this->assertStringContainsString('formatCountPercent(row.gestionados, row.gestionados_pct)', $js);
        $this->assertStringContainsString('formatCountPercent(row.llamadas, row.llamadas_pct)', $js);
        $this->assertStringContainsString('formatCountPercent(row.formularios, row.formularios_pct)', $js);
        $this->assertStringContainsString('data-column="conversion_pct">% convertidos</th>', $blade);
        $this->assertStringContainsString('data-column="descarte_pct">% descartados</th>', $blade);
        $this->assertStringContainsString('data-column="gestionados_pct">% gestionados</th>', $blade);
        $this->assertStringContainsString('class="num is-hidden" data-column="conversion_pct"', $blade);
    }
}
