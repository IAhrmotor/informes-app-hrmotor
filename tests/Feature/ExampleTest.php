<?php

test('the application returns a successful response', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('reports.leads.index'));
});

test('the leads dashboard returns a successful response', function () {
    $this->get('/informes/leads')
        ->assertOk()
        ->assertSee('Resumen Dirección')
        ->assertSee('Comerciales/Delegaciones/Zonas')
        ->assertSee('Delegaciones por reparto de leads')
        ->assertSee('Portales / Procedencia')
        ->assertDontSee('Informe mensual');
});
