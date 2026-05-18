<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardSortingCompactColumnsTest extends TestCase
{
    public function test_columnas_compactas_exponen_sort_value_numerico(): void
    {
        $js = file_get_contents(resource_path('js/reports/leads-dashboard.js'));

        $this->assertStringContainsString('data-sort-value', $js);
        $this->assertStringContainsString('(row) => row.convertidos', $js);
        $this->assertStringContainsString('(row) => row.descartados', $js);
        $this->assertStringContainsString('(row) => row.gestionados', $js);
        $this->assertStringContainsString("raw.split('(')[0]", $js);
    }
}
