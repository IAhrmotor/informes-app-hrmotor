<?php

namespace App\Http\Controllers\Reports\CommercialCommissions;

use App\Http\Controllers\Controller;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

class CommercialCommissionDashboardController extends Controller
{
    public function index(
        Request $request,
        CommercialCommissionDashboardService $dashboard,
        CommercialCommissionFormulaConfigService $formulaConfig,
    )
    {
        $selectedMonth = $request->query('month');

        if (! ReportUserAccess::canViewCommercialCommissions($request)) {
            return redirect()->route('reports.leads.index');
        }

        $payload = $dashboard->build($selectedMonth);

        return view('reports.commercial-commissions.index', [
            'reportUserRole' => ReportUserAccess::role($request),
            'canSeeSyncDiagnostics' => ReportUserAccess::canSeeSyncDiagnostics($request),
            'selectedMonth' => $selectedMonth,
            'dashboard' => $payload,
            'formulaSettings' => $formulaConfig->forMonth($payload['month']),
        ]);
    }
}
