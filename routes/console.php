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
