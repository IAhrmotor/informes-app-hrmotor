<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardMetricPercentStyleTest extends TestCase
{
    public function test_metric_percent_tiene_estilo_destacado_y_mas_pequeno(): void
    {
        $css = file_get_contents(resource_path('css/reports/leads-dashboard.css'));
        $js = file_get_contents(resource_path('js/reports/leads-dashboard.js'));

        $this->assertStringContainsString('class="metric-value"', $js);
        $this->assertStringContainsString('class="metric-percent"', $js);
        $this->assertStringContainsString('.metric-percent', $css);
        $this->assertStringContainsString('font-weight: 800', $css);
        $this->assertStringContainsString('font-size: .92em', $css);
        $this->assertStringContainsString('white-space: nowrap', $css);
    }
}
