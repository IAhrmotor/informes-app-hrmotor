<?php

namespace App\Http\Controllers\Reports\CommercialCommissions;

use App\Http\Controllers\Controller;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
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
        CallCenterCommissionDashboardService $callCenterDashboard,
    )
    {
        $selectedMonth = $request->query('month');
        $callCenterContractFrom = $request->query('call_center_contract_from');
        $callCenterContractTo = $request->query('call_center_contract_to');

        if (! ReportUserAccess::canViewCommercialCommissions($request)) {
            return redirect()->route('reports.leads.index');
        }

        $payload = $dashboard->build($selectedMonth);

        return view('reports.commercial-commissions.index', [
            'reportUserRole' => ReportUserAccess::role($request),
            'canSeeSyncDiagnostics' => ReportUserAccess::canSeeSyncDiagnostics($request),
            'selectedMonth' => $selectedMonth,
            'dashboard' => $payload,
            'callCenterDashboard' => $callCenterDashboard->build(
                $payload['month'],
                is_string($callCenterContractFrom) ? $callCenterContractFrom : null,
                is_string($callCenterContractTo) ? $callCenterContractTo : null
            ),
            'formulaSettings' => $formulaConfig->forMonth($payload['month']),
        ]);
    }
}
