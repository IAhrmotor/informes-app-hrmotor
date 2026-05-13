<?php

test('the application returns a successful response', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('reports.leads.index'));
});

test('the leads dashboard returns a successful response', function () {
    $this->get('/informes/leads')
        ->assertOk()
        ->assertSee('Informe mensual');
});
