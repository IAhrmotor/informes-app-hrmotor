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
