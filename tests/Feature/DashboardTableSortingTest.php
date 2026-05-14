<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTableSortingTest extends TestCase
{
    public function test_frontend_incluye_helpers_de_ordenacion_y_cabeceras_ordenables(): void
    {
        $js = file_get_contents(resource_path('js/reports/leads-dashboard.js'));

        $this->assertStringContainsString('function makeTableSortable', $js);
        $this->assertStringContainsString('function parseSortableValue', $js);
        $this->assertStringContainsString('function sortRowsByColumn', $js);
        $this->assertStringContainsString("header.dataset.sortable = 'true'", $js);
        $this->assertStringContainsString('sort-indicator', $js);
    }
}
