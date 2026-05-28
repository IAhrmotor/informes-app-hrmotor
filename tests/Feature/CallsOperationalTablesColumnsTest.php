<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallsOperationalTablesColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_calls_operational_tables_use_clean_column_sets(): void
    {
        $html = $this->get('/informes/llamadas')->assertOk()->content();

        $commercials = $this->section($html, '<h2>Comerciales</h2>', '<h2>Atencion al Cliente</h2>');
        $customerService = $this->section($html, '<h2>Atencion al Cliente</h2>', '<h2>Contact Center</h2>');
        $contactCenter = $this->section($html, '<h2>Contact Center</h2>', '<h2>Tasadores</h2>');
        $appraisers = $this->section($html, '<h2>Tasadores</h2>', '<h2>Zonas</h2>');
        $delegations = $this->section($html, '<h2>Delegaciones</h2>', '<h2>Portales / Procedencia</h2>');
        $portals = $this->section($html, '<h2>Portales / Procedencia</h2>', '</main>');

        $this->assertStringNotContainsString('Desbordes</th>', $commercials);
        $this->assertStringNotContainsString('Zona</th>', $commercials);
        $this->assertStringNotContainsString('Delegacion</th>', $customerService);
        $this->assertStringNotContainsString('Zona</th>', $customerService);
        $this->assertStringNotContainsString('Delegacion</th>', $contactCenter);
        $this->assertStringNotContainsString('Zona</th>', $contactCenter);
        $this->assertStringNotContainsString('Delegacion</th>', $appraisers);
        $this->assertStringNotContainsString('Zona</th>', $appraisers);
        $this->assertStringNotContainsString('Desbordes</th>', $appraisers);
        $this->assertStringNotContainsString('Zona</th>', $delegations);
        $this->assertStringNotContainsString('Salientes</th>', $portals);
    }

    private function section(string $html, string $start, string $end): string
    {
        $startAt = strpos($html, $start);
        $this->assertNotFalse($startAt, $start);
        $endAt = strpos($html, $end, $startAt);
        $this->assertNotFalse($endAt, $end);

        return substr($html, $startAt, $endAt - $startAt);
    }
}
