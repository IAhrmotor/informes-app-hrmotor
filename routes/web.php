<?php

use App\Http\Controllers\Reports\Leads\LeadDashboardController;
use App\Http\Controllers\Reports\Leads\LeadDashboardDataController;
use App\Http\Controllers\Reports\Leads\MonthlyCommercialReportDataController;
use App\Http\Controllers\Reports\Calls\CallDashboardController;
use App\Http\Controllers\Reports\Calls\CallDashboardDataController;
use App\Http\Controllers\Reports\ReservationsSales\ReservationsSalesDashboardController;
use App\Http\Controllers\Reports\ReservationsSales\ReservationsSalesDashboardDataController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('reports.leads.index');
});

Route::prefix('informes/leads')
    ->name('reports.leads.')
    ->group(function () {
        Route::get('/', [LeadDashboardController::class, 'index'])->name('index');

        Route::get('/data/resumen', [LeadDashboardDataController::class, 'resumen'])->name('data.resumen');
        Route::get('/data/summary', [LeadDashboardDataController::class, 'resumen'])->name('data.summary');
        Route::get('/data/kpis', [LeadDashboardDataController::class, 'kpis'])->name('data.kpis');
        Route::get('/data/portales', [LeadDashboardDataController::class, 'portales'])->name('data.portales');
        Route::get('/data/portals', [LeadDashboardDataController::class, 'portales'])->name('data.portals');
        Route::get('/data/portal-detalle', [LeadDashboardDataController::class, 'portalDetalle'])->name('data.portal-detalle');
        Route::get('/data/delegaciones', [LeadDashboardDataController::class, 'delegaciones'])->name('data.delegaciones');
        Route::get('/data/delegations', [LeadDashboardDataController::class, 'delegaciones'])->name('data.delegations');
        Route::get('/data/comerciales', [LeadDashboardDataController::class, 'comerciales'])->name('data.comerciales');
        Route::get('/data/commercials', [LeadDashboardDataController::class, 'comerciales'])->name('data.commercials');
        Route::get('/data/comparativa', [LeadDashboardDataController::class, 'comparativa'])->name('data.comparativa');
        Route::get('/data/calidad-dato', [LeadDashboardDataController::class, 'calidadDato'])->name('data.calidad-dato');

        Route::get('/data/monthly-commercial/summary', [MonthlyCommercialReportDataController::class, 'summary'])->name('data.monthly-commercial.summary');
        Route::get('/data/monthly-commercial/evolution', [MonthlyCommercialReportDataController::class, 'evolution'])->name('data.monthly-commercial.evolution');
        Route::get('/data/monthly-commercial/kpis', [MonthlyCommercialReportDataController::class, 'kpis'])->name('data.monthly-commercial.kpis');
        Route::get('/data/monthly-commercial/commercial-pending', [MonthlyCommercialReportDataController::class, 'commercialPending'])->name('data.monthly-commercial.commercial-pending');
        Route::get('/data/monthly-commercial/commercial-performance', [MonthlyCommercialReportDataController::class, 'commercialPerformance'])->name('data.monthly-commercial.commercial-performance');
        Route::get('/data/monthly-commercial/portals', [MonthlyCommercialReportDataController::class, 'portals'])->name('data.monthly-commercial.portals');
        Route::get('/data/monthly-commercial/delegations', [MonthlyCommercialReportDataController::class, 'delegations'])->name('data.monthly-commercial.delegations');
        Route::get('/data/monthly-commercial/delegation-pending', [MonthlyCommercialReportDataController::class, 'delegationPending'])->name('data.monthly-commercial.delegation-pending');
    });

Route::prefix('informes/reservas-ventas')
    ->name('reports.reservations-sales.')
    ->group(function () {
        Route::get('/', [ReservationsSalesDashboardController::class, 'index'])->name('index');
        Route::get('/data/summary', [ReservationsSalesDashboardDataController::class, 'summary'])->name('data.summary');
        Route::get('/data/commercials', [ReservationsSalesDashboardDataController::class, 'commercials'])->name('data.commercials');
        Route::get('/data/portals', [ReservationsSalesDashboardDataController::class, 'portals'])->name('data.portals');
    });

Route::prefix('informes/llamadas')
    ->name('reports.calls.')
    ->group(function () {
        Route::get('/', [CallDashboardController::class, 'index'])->name('index');
        Route::get('/data/summary', [CallDashboardDataController::class, 'summary'])->name('data.summary');
        Route::get('/data/agents', [CallDashboardDataController::class, 'agents'])->name('data.agents');
        Route::get('/data/delegations', [CallDashboardDataController::class, 'delegations'])->name('data.delegations');
        Route::get('/data/portals', [CallDashboardDataController::class, 'portals'])->name('data.portals');
    });
