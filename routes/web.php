<?php

use App\Http\Controllers\Auth\InformesLoginController;
use App\Http\Controllers\Reports\Leads\LeadDashboardController;
use App\Http\Controllers\Reports\Leads\LeadDashboardDataController;
use App\Http\Controllers\Reports\Leads\MonthlyCommercialReportDataController;
use App\Http\Controllers\Reports\Calls\CallDashboardController;
use App\Http\Controllers\Reports\Calls\CallDashboardDataController;
use App\Http\Controllers\Reports\Campaigns\CampaignDashboardController;
use App\Http\Controllers\Reports\Campaigns\CampaignDashboardDataController;
use App\Http\Controllers\Reports\CommercialCommissions\CommercialCommissionDashboardController;
use App\Http\Controllers\Reports\CommercialCommissions\CommercialCommissionFormulaSettingsController;
use App\Http\Controllers\Reports\ReservationsSales\ReservationsSalesDashboardController;
use App\Http\Controllers\Reports\ReservationsSales\ReservationsSalesDashboardDataController;
use App\Http\Controllers\Reports\Settings\ReportAccessManagementController;
use App\Http\Controllers\Reports\Users\ReportUserManagementController;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('reports.index');
});

Route::get('/login', [InformesLoginController::class, 'show'])->name('login');
Route::post('/login', [InformesLoginController::class, 'login'])->name('login.post');
Route::post('/logout', [InformesLoginController::class, 'logout'])->name('logout');

Route::middleware('reports.auth')->group(function () {
    Route::get('informes', function (Request $request) {
        $routeName = ReportUserAccess::defaultAccessibleRouteName($request);

        abort_if($routeName === null, 403);

        return redirect()->route($routeName);
    })->name('reports.index');

    Route::prefix('informes/leads')
        ->name('reports.leads.')
        ->middleware('report.access:leads')
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
            Route::get('/data/kpi-audit', [LeadDashboardDataController::class, 'kpiAudit'])->name('data.kpi-audit');
            Route::get('/export/kpi-audit.csv', [LeadDashboardDataController::class, 'exportKpiAuditCsv'])->name('export.kpi-audit');

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
        ->middleware('report.access:reservations-sales')
        ->group(function () {
            Route::get('/', [ReservationsSalesDashboardController::class, 'index'])->name('index');
            Route::get('/data/summary', [ReservationsSalesDashboardDataController::class, 'summary'])->name('data.summary');
            Route::get('/data/commercials', [ReservationsSalesDashboardDataController::class, 'commercials'])->name('data.commercials');
            Route::get('/data/portals', [ReservationsSalesDashboardDataController::class, 'portals'])->name('data.portals');
            Route::get('/data/kpi-audit', [ReservationsSalesDashboardDataController::class, 'kpiAudit'])->name('data.kpi-audit');
            Route::get('/export/kpi-audit.csv', [ReservationsSalesDashboardDataController::class, 'exportKpiAuditCsv'])->name('export.kpi-audit');
        });

    Route::prefix('informes/llamadas')
        ->name('reports.calls.')
        ->middleware('report.access:calls')
        ->group(function () {
            Route::get('/', [CallDashboardController::class, 'index'])->name('index');
            Route::get('/data/summary', [CallDashboardDataController::class, 'summary'])->name('data.summary');
            Route::get('/data/agents', [CallDashboardDataController::class, 'agents'])->name('data.agents');
            Route::get('/data/delegations', [CallDashboardDataController::class, 'delegations'])->name('data.delegations');
            Route::get('/data/portals', [CallDashboardDataController::class, 'portals'])->name('data.portals');
        });

    Route::prefix('informes/campanas')
        ->name('reports.campaigns.')
        ->middleware('report.access:campaigns')
        ->group(function () {
            Route::get('/', [CampaignDashboardController::class, 'index'])->name('index');
            Route::get('/data/summary', [CampaignDashboardDataController::class, 'summary'])->name('data.summary');
            Route::get('/data/campaigns', [CampaignDashboardDataController::class, 'campaigns'])->name('data.campaigns');
            Route::get('/data/rankings', [CampaignDashboardDataController::class, 'rankings'])->name('data.rankings');
            Route::get('/data/kpi-audit', [CampaignDashboardDataController::class, 'kpiAudit'])->name('data.kpi-audit');
            Route::get('/export/campaigns.csv', [CampaignDashboardDataController::class, 'exportCampaignsCsv'])->name('export.campaigns');
            Route::get('/export/kpi-audit.csv', [CampaignDashboardDataController::class, 'exportKpiAuditCsv'])->name('export.kpi-audit');
        });

    Route::prefix('informes/comisiones-comerciales')
        ->name('reports.commercial-commissions.')
        ->middleware('report.access:commercial-commissions')
        ->group(function () {
            Route::get('/', [CommercialCommissionDashboardController::class, 'index'])->name('index');
            Route::get('/export/call-center-missing-captador.csv', [CommercialCommissionDashboardController::class, 'exportCallCenterMissingCaptadorCsv'])->name('export.call-center-missing-captador');
        });

    Route::prefix('informes/usuarios')
        ->name('reports.users.')
        ->group(function () {
            Route::get('/', [ReportUserManagementController::class, 'index'])->name('index');
            Route::post('/', [ReportUserManagementController::class, 'store'])->name('store');
            Route::get('/{reportUser}/editar', [ReportUserManagementController::class, 'edit'])->name('edit');
            Route::put('/{reportUser}', [ReportUserManagementController::class, 'update'])->name('update');
            Route::delete('/{reportUser}', [ReportUserManagementController::class, 'destroy'])->name('destroy');
        });

    Route::prefix('informes/permisos-informes')
        ->name('reports.access-settings.')
        ->group(function () {
            Route::get('/', [ReportAccessManagementController::class, 'index'])->name('index');
            Route::put('/', [ReportAccessManagementController::class, 'update'])->name('update');
        });

    Route::prefix('informes/configuracion-comisiones')
        ->name('reports.commission-settings.')
        ->group(function () {
            Route::get('/', [CommercialCommissionFormulaSettingsController::class, 'index'])->name('index');
            Route::post('/unlock', [CommercialCommissionFormulaSettingsController::class, 'unlock'])->name('unlock');
            Route::put('/', [CommercialCommissionFormulaSettingsController::class, 'update'])->name('update');
        });
});
