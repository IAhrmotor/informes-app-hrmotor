<?php

use App\Http\Controllers\Reports\Leads\LeadDashboardController;
use App\Http\Controllers\Reports\Leads\LeadDashboardDataController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('reports.leads.index');
});

Route::prefix('informes/leads')
    ->name('reports.leads.')
    ->group(function () {
        Route::get('/', [LeadDashboardController::class, 'index'])->name('index');

        Route::get('/data/resumen', [LeadDashboardDataController::class, 'resumen'])->name('data.resumen');
        Route::get('/data/kpis', [LeadDashboardDataController::class, 'kpis'])->name('data.kpis');
        Route::get('/data/portales', [LeadDashboardDataController::class, 'portales'])->name('data.portales');
        Route::get('/data/portal-detalle', [LeadDashboardDataController::class, 'portalDetalle'])->name('data.portal-detalle');
        Route::get('/data/delegaciones', [LeadDashboardDataController::class, 'delegaciones'])->name('data.delegaciones');
        Route::get('/data/comerciales', [LeadDashboardDataController::class, 'comerciales'])->name('data.comerciales');
        Route::get('/data/comparativa', [LeadDashboardDataController::class, 'comparativa'])->name('data.comparativa');
        Route::get('/data/calidad-dato', [LeadDashboardDataController::class, 'calidadDato'])->name('data.calidad-dato');
    });