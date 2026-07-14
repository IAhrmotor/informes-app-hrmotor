<?php

namespace App\Http\Controllers\Reports\CommercialCommissions;

use App\Http\Controllers\Controller;
use App\Services\Reports\AreaManagerCommissions\AreaManagerCommissionDashboardService;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionDashboardService;
use App\Services\Reports\FinancialCommissions\FinancialCommissionDashboardService;
use App\Support\ReportUserAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class CommercialCommissionDashboardController extends Controller
{
    public function index(
        Request $request,
        CommercialCommissionDashboardService $dashboard,
        CommercialCommissionFormulaConfigService $formulaConfig,
        CallCenterCommissionDashboardService $callCenterDashboard,
        ContactCenterCommissionDashboardService $contactCenterDashboard,
        AreaManagerCommissionDashboardService $areaManagerDashboard,
        FinancialCommissionDashboardService $financialDashboard,
    )
    {
        $selectedMonth = $request->query('month');
        $callCenterContractFrom = $request->query('call_center_contract_from');
        $callCenterContractTo = $request->query('call_center_contract_to');
        $activeCommissionTab = $this->resolveActiveTab($request->query('tab'));

        if (! ReportUserAccess::canViewCommercialCommissions($request)) {
            return redirect()->route('reports.leads.index');
        }

        $payload = $dashboard->build(
            $selectedMonth,
            includeSummaryRows: $activeCommissionTab === 'summary',
            includeDelegationRows: $activeCommissionTab === 'delegations',
            includeDetails: $activeCommissionTab === 'summary',
        );

        $callCenterPayload = $activeCommissionTab === 'call-center'
            ? $callCenterDashboard->build(
                $payload['month'],
                is_string($callCenterContractFrom) ? $callCenterContractFrom : null,
                is_string($callCenterContractTo) ? $callCenterContractTo : null
            )
            : $this->emptyCallCenterDashboard(
                $payload['month'],
                $payload['month_label'],
                is_string($callCenterContractFrom) ? $callCenterContractFrom : null,
                is_string($callCenterContractTo) ? $callCenterContractTo : null
            );
        $contactCenterPayload = $activeCommissionTab === 'contact-center'
            ? $contactCenterDashboard->build($payload['month'])
            : $this->emptyContactCenterDashboard($payload['month'], $payload['month_label']);
        $areaManagerPayload = $activeCommissionTab === 'area-manager'
            ? $areaManagerDashboard->build($payload['month'])
            : $this->emptyAreaManagerDashboard($payload['month'], $payload['month_label']);
        $financialPayload = $activeCommissionTab === 'financials'
            ? $financialDashboard->build($payload['month'])
            : $this->emptyFinancialDashboard($payload['month'], $payload['month_label']);

        return view('reports.commercial-commissions.index', [
            'activeCommissionTab' => $activeCommissionTab,
            'reportUserRole' => ReportUserAccess::role($request),
            'canSeeSyncDiagnostics' => ReportUserAccess::canSeeSyncDiagnostics($request),
            'selectedMonth' => $selectedMonth,
            'dashboard' => $payload,
            'callCenterDashboard' => $callCenterPayload,
            'contactCenterDashboard' => $contactCenterPayload,
            'areaManagerDashboard' => $areaManagerPayload,
            'financialDashboard' => $financialPayload,
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

    private function resolveActiveTab(mixed $value): string
    {
        if ($value === 'detail') {
            return 'summary';
        }

        $allowedTabs = ['summary', 'delegations', 'call-center', 'contact-center', 'area-manager', 'financials'];

        return in_array($value, $allowedTabs, true) ? $value : 'summary';
    }

    private function emptyCallCenterDashboard(string $month, string $monthLabel, ?string $contractFrom, ?string $contractTo): array
    {
        return [
            'ready' => false,
            'month' => $month,
            'month_label' => $monthLabel,
            'contract_from' => $contractFrom,
            'contract_to' => $contractTo,
            'issues' => [],
            'warnings' => [],
            'diagnostics' => [],
            'summary_rows' => [],
        ];
    }

    private function emptyContactCenterDashboard(string $month, string $monthLabel): array
    {
        $monthStart = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();

        return [
            'ready' => false,
            'month' => $month,
            'month_label' => $monthLabel,
            'closure_cutoff_date' => $monthStart->endOfMonth()->toDateString(),
            'issues' => [],
            'warnings' => [],
            'diagnostics' => [],
            'summary_rows' => [],
            'global_incidents' => [],
        ];
    }

    private function emptyAreaManagerDashboard(string $month, string $monthLabel): array
    {
        return [
            'ready' => false,
            'month' => $month,
            'month_label' => $monthLabel,
            'issues' => [],
            'warnings' => [],
            'diagnostics' => [],
            'summary_rows' => [],
            'global_incidents' => [],
        ];
    }

    private function emptyFinancialDashboard(string $month, string $monthLabel): array
    {
        return [
            'ready' => false,
            'month' => $month,
            'month_label' => $monthLabel,
            'issues' => [],
            'warnings' => [],
            'diagnostics' => [],
            'summary_rows' => [],
        ];
    }
}
