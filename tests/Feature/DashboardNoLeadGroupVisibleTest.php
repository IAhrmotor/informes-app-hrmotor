<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardNoLeadGroupVisibleTest extends TestCase
{
    public function test_grupo_lead_no_aparece_en_html_ni_js_visible(): void
    {
        $response = $this->get('/informes/leads');

        $response->assertOk();
        $response->assertDontSee('leadGroup');
        $response->assertDontSee('Grupo Lead');
        $response->assertDontSee('Grupo del lead');

        $js = file_get_contents(resource_path('js/reports/leads-dashboard.js'));

        $this->assertStringNotContainsString('leadGroup', $js);
        $this->assertStringNotContainsString('lead_group', $js);
    }
}
