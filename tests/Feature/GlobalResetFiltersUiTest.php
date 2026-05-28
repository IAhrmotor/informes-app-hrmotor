<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalResetFiltersUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_report_dashboards_include_reset_filters_button(): void
    {
        foreach ([
            '/informes/leads',
            '/informes/reservas-ventas',
            '/informes/llamadas',
            '/informes/campanas',
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Limpiar filtros');
        }
    }
}
