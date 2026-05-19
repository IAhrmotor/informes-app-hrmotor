<?php

namespace Tests\Feature;

use Tests\TestCase;

class LeadsDelegationsUiColumnsTest extends TestCase
{
    public function test_tabla_de_delegaciones_de_reparto_no_muestra_zona_comercial_ni_grupo_lead(): void
    {
        $response = $this->get('/informes/leads');

        $response->assertOk();

        $section = $this->sectionBetween(
            $response->getContent(),
            '<section id="panel-delegaciones"',
            '<section id="panel-portales"'
        );

        $this->assertMatchesRegularExpression('/Delegaci.{1,4}n del lead/', $section);
        $this->assertStringContainsString('delegationRows', $section);
        $this->assertStringContainsString('Leads totales', $section);
        $this->assertStringNotContainsString('Zona comercial', $section);
        $this->assertStringNotContainsString('Grupo Lead', $section);

        $js = file_get_contents(resource_path('js/reports/leads-dashboard.js'));
        $renderDelegations = $this->sectionBetween($js, 'function renderDelegations', 'function renderPortals');

        $this->assertStringContainsString('(row) => row.delegacion', $renderDelegations);
        $this->assertStringNotContainsString('row.zone', $renderDelegations);
    }

    private function sectionBetween(string $content, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($content, $startNeedle);
        $this->assertNotFalse($start);

        $end = strpos($content, $endNeedle, $start);
        $this->assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }
}
