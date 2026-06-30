<?php

namespace App\Http\Controllers\Reports\CommercialCommissions;

use App\Http\Controllers\Controller;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionDashboardService;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

class CommercialCommissionDashboardController extends Controller
{
    public function index(
        Request $request,
        CommercialCommissionDashboardService $dashboard,
        CommercialCommissionFormulaConfigService $formulaConfig,
        CallCenterCommissionDashboardService $callCenterDashboard,
        ContactCenterCommissionDashboardService $contactCenterDashboard,
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
            'contactCenterDashboard' => $contactCenterDashboard->build($payload['month']),
            'formulaSettings' => $formulaConfig->forMonth($payload['month']),
        ]);
    }

    public function exportCallCenterMissingCaptadorCsv(
        Request $request,
        CallCenterCommissionDashboardService $callCenterDashboard,
    ) {
        $audit = $callCenterDashboard->missingCaptadorAudit(
            $request->query('month'),
            is_string($request->query('call_center_contract_from')) ? $request->query('call_center_contract_from') : null,
            is_string($request->query('call_center_contract_to')) ? $request->query('call_center_contract_to') : null,
        );

        abort_unless($audit['ready'], 409, implode(' | ', $audit['issues'] ?? ['No se pudo preparar la auditoria.']));

        $rows = $audit['rows'] ?? [];
        $headers = [
            'Opportunity Id',
            'Opportunity Name',
            'Record Type',
            'Stage',
            'Owner',
            'Account',
            'Fecha firma contrato',
            'Fuente',
            'Campos senal',
        ];
        $filename = 'call-center-sin-captador-'.$audit['month'].'.csv';

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['opportunity_id'] ?? '',
                    $row['opportunity_name'] ?? '',
                    $row['record_type_name'] ?? '',
                    $row['stage_name'] ?? '',
                    $row['owner_name'] ?? '',
                    $row['account_name'] ?? '',
                    $row['contract_signed_date'] ?? '',
                    $row['source'] ?? '',
                    $row['signal_fields'] ?? '',
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
