<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('stock:sync-daily --sales-days=14 --logistics-days=30')
    ->dailyAt('03:30')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping(120);

// The query also includes old Leads modified in this window, so a short
// incremental window refreshes status/owner/type/portal changes and deletions.
Schedule::command('salesforce:sync-monthly-commercial --days=2')
    ->everyFifteenMinutes()
    ->timezone('Europe/Madrid')
    ->withoutOverlapping(30);

// Ventana movil: no requiere editar fechas cada dia. El comando calcula
// [ahora - 120 dias, ahora) e incluye registros antiguos modificados.
Schedule::command('salesforce:sync-tasaciones --days=120')
    ->dailyAt('01:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping(180);

// La atribucion se reconstruye despues de actualizar ambas plataformas.
Schedule::command('campaigns:sync-meta --days=120')
    ->dailyAt('01:30')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping(180);

Schedule::command('campaigns:sync-google --days=120')
    ->dailyAt('01:45')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping(180);

Schedule::command('campaigns:build-attribution --days=120')
    ->dailyAt('02:15')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping(240);

Schedule::command('reports:refresh-campaigns --days=120 --store')
    ->dailyAt('03:15')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping(120);
