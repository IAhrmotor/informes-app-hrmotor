<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallsResetFiltersUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_calls_dashboard_includes_reset_filters_button(): void
    {
        $this->get('/informes/llamadas')
            ->assertOk()
            ->assertSee('Limpiar filtros');
    }
}
