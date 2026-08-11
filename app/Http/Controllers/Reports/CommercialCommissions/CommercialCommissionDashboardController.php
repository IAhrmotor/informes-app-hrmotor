<?php

namespace App\Http\Controllers\Reports\CommercialCommissions;

use App\Http\Controllers\Controller;
use App\Models\ReportUser;
use App\Services\Reports\AreaManagerCommissions\AreaManagerCommissionDashboardService;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\AreaRestrictedCommissionScope;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionDashboardService;
use App\Services\Reports\FinancialCommissions\FinancialCommissionDashboardService;
use App\Support\ReportUserAccess;
use App\Support\SimpleXlsxWorkbookWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        AreaRestrictedCommissionScope $areaScope,
        CommercialCommissionClosureService $closureService,
    ) {
        $selectedMonth = $request->query('month');
        $callCenterContractFrom = $request->query('call_center_contract_from');
        $callCenterContractTo = $request->query('call_center_contract_to');
        $isAreaRestricted = ReportUserAccess::isAreaManager($request);
        $areaZoneLabel = ReportUserAccess::areaZoneLabel($request);
        $role = ReportUserAccess::role($request);
        $delegationScope = ReportUserAccess::delegationName($request);
        $commercialScope = ReportUserAccess::salesforceUserId($request);
        $activeCommissionTab = match ($role) {
            ReportUser::ROLE_FINANCIAL => 'financials',
            ReportUser::ROLE_DELEGATION_MANAGER => 'delegations',
            ReportUser::ROLE_COMMERCIAL => 'summary',
            default => $this->resolveActiveTab($request->query('tab'), $isAreaRestricted),
        };

        if (! ReportUserAccess::canViewCommercialCommissions($request)) {
            return redirect()->route('reports.leads.index');
        }

        $payload = $dashboard->build(
            $selectedMonth,
            includeSummaryRows: $activeCommissionTab === 'summary',
            includeDelegationRows: $activeCommissionTab === 'delegations',
            includeDetails: $activeCommissionTab === 'summary',
        );
        $closureScope = match ($activeCommissionTab) {
            'summary' => 'commercials',
            'delegations' => 'delegations',
            'area-manager' => 'area_manager',
            default => null,
        };
        $commissionClosure = $closureScope ? $closureService->status($payload['month'], $closureScope) : null;
        $frozenSnapshot = $closureScope ? $closureService->definitiveSnapshot($payload['month'], $closureScope) : null;
        if ($frozenSnapshot !== null && $closureScope === 'commercials') {
            $payload = $frozenSnapshot['commercials'];
            $payload['economic_status'] = 'definitive';
            $payload['dataset_cutoff_at'] = $commissionClosure['data_cutoff_at'];
            $payload['formula_version'] = $commissionClosure['formula_version'];
        }
        if ($frozenSnapshot !== null && $closureScope === 'delegations') {
            $payload = $frozenSnapshot['delegations'];
            $payload['economic_status'] = 'definitive';
            $payload['dataset_cutoff_at'] = $commissionClosure['data_cutoff_at'];
            $payload['formula_version'] = $commissionClosure['formula_version'];
        }

        if ($isAreaRestricted) {
            if ($areaZoneLabel === null) {
                $payload['summary_rows'] = [];
                $payload['delegation_rows'] = [];
                $payload['issues'][] = 'El usuario Area Manager no tiene una zona configurada.';
            } else {
                $payload = $areaScope->commercialDashboard(
                    $payload,
                    $areaZoneLabel,
                    ReportUserAccess::current($request)['email'] ?? null,
                );
            }
        }
        if ($role === ReportUser::ROLE_DELEGATION_MANAGER) {
            abort_if($delegationScope === null, 403, 'El responsable no tiene delegación configurada.');
            $payload = $areaScope->delegationDashboard($payload, $delegationScope);
        }
        if ($role === ReportUser::ROLE_COMMERCIAL) {
            abort_if($commercialScope === null, 403, 'El comercial no tiene Salesforce User ID configurado.');
            $payload = $areaScope->commercialDashboardByUser($payload, $commercialScope);
        }

        $callCenterPayload = $activeCommissionTab === 'call-center'
            ? ($frozenSnapshot['call_center'] ?? $callCenterDashboard->build(
                $payload['month'],
                is_string($callCenterContractFrom) ? $callCenterContractFrom : null,
                is_string($callCenterContractTo) ? $callCenterContractTo : null
            ))
            : $this->emptyCallCenterDashboard(
                $payload['month'],
                $payload['month_label'],
                is_string($callCenterContractFrom) ? $callCenterContractFrom : null,
                is_string($callCenterContractTo) ? $callCenterContractTo : null
            );
        $contactCenterPayload = $activeCommissionTab === 'contact-center'
            ? ($frozenSnapshot['contact_center'] ?? $contactCenterDashboard->build($payload['month']))
            : $this->emptyContactCenterDashboard($payload['month'], $payload['month_label']);
        $areaManagerPayload = $activeCommissionTab === 'area-manager'
            ? (($isAreaRestricted
                ? data_get($frozenSnapshot, 'area_manager_by_zone.'.ReportUserAccess::areaZoneKey($request))
                : ($frozenSnapshot['area_manager'] ?? null))
                ?? $areaManagerDashboard->build($payload['month'], $isAreaRestricted ? $areaZoneLabel : null))
            : $this->emptyAreaManagerDashboard($payload['month'], $payload['month_label']);
        $financialPayload = $activeCommissionTab === 'financials'
            ? ($frozenSnapshot['financials'] ?? $financialDashboard->build($payload['month']))
            : $this->emptyFinancialDashboard($payload['month'], $payload['month_label']);
        $universeReconciliation = $this->universeReconciliation(
            $activeCommissionTab,
            $payload,
            $callCenterPayload,
            $contactCenterPayload,
            $areaManagerPayload,
            $financialPayload,
        );

        return view('reports.commercial-commissions.index', [
            'activeCommissionTab' => $activeCommissionTab,
            'reportUserRole' => ReportUserAccess::role($request),
            'canSeeSyncDiagnostics' => ReportUserAccess::canSeeSyncDiagnostics($request),
            'canBrowseAreaManagers' => ReportUserAccess::canBrowseAreaManagers($request),
            'isAreaRestricted' => $isAreaRestricted,
            'areaZoneLabel' => $areaZoneLabel,
            'selectedMonth' => $selectedMonth,
            'dashboard' => $payload,
            'callCenterDashboard' => $callCenterPayload,
            'contactCenterDashboard' => $contactCenterPayload,
            'areaManagerDashboard' => $areaManagerPayload,
            'financialDashboard' => $financialPayload,
            'formulaSettings' => $frozenSnapshot['formula_settings'] ?? $formulaConfig->forMonth($payload['month']),
            'commissionClosure' => $commissionClosure,
            'closureScope' => $closureScope,
            'canManageEconomicClosures' => ReportUserAccess::canManageEconomicClosures($request),
            'canManageFinancingPenalties' => ReportUserAccess::canManageFinancingPenalties($request),
            'canSeeUniverseReconciliation' => ReportUserAccess::isAdmin($request),
            'canAuditEconomicReviews' => ReportUserAccess::canAudit($request),
            'canAuditDelegationDeliveries' => in_array($role, [
                ReportUser::ROLE_ADMIN,
                ReportUser::ROLE_DIRECTOR,
                ReportUser::ROLE_AREA_MANAGER,
                ReportUser::ROLE_DELEGATION_MANAGER,
            ], true),
            'universeReconciliation' => $universeReconciliation,
        ]);
    }

    private function universeReconciliation(
        string $tab,
        array $commercials,
        array $callCenter,
        array $contactCenter,
        array $areaManager,
        array $financials,
    ): array {
        return match ($tab) {
            'delegations' => $this->bridge(
                (int) data_get($commercials, 'diagnostics.opportunities_total', 0),
                max(0, (int) data_get($commercials, 'diagnostics.opportunities_total', 0)
                    - (int) data_get($commercials, 'diagnostics.sales_count', 0)),
                0,
                (int) data_get($commercials, 'diagnostics.sales_count', 0),
                'Oportunidades del mes', 'No son entregas validas de Delegaciones', 'Inclusiones especiales', 'Entregas mostradas'
            ),
            'call-center' => $this->bridge(
                (int) data_get($callCenter, 'diagnostics.monthly_opportunities', 0),
                0,
                (int) data_get($callCenter, 'diagnostics.monthly_tasaciones', 0),
                (int) data_get($callCenter, 'diagnostics.monthly_opportunities', 0)
                    + (int) data_get($callCenter, 'diagnostics.monthly_tasaciones', 0),
                'Oportunidades procesadas', 'Exclusiones previas segun reglas actuales', 'Incluye tasaciones sincronizadas', 'Registros fuente evaluados'
            ),
            'contact-center' => $this->bridge(
                (int) data_get($contactCenter, 'diagnostics.appointments_count', 0),
                0,
                (int) data_get($contactCenter, 'diagnostics.sales_without_appointment_count', 0),
                (int) data_get($contactCenter, 'diagnostics.appointments_count', 0)
                    + (int) data_get($contactCenter, 'diagnostics.sales_without_appointment_count', 0),
                'Citas del mes', 'Exclusiones previas segun reglas actuales', 'Ventas sin cita vinculable', 'Apariciones auditables'
            ),
            'area-manager' => $this->bridge(
                (int) data_get($areaManager, 'diagnostics.delivery_operations_count', 0),
                0,
                (int) data_get($areaManager, 'diagnostics.purchase_operations_count', 0),
                (int) data_get($areaManager, 'diagnostics.delivery_operations_count', 0)
                    + (int) data_get($areaManager, 'diagnostics.purchase_operations_count', 0),
                'Entregas', 'Exclusiones previas segun reglas actuales', 'Compras', 'Apariciones evaluadas'
            ),
            'financials' => $this->bridge(
                (int) data_get($financials, 'diagnostics.eligible_operations_count', 0),
                0,
                0,
                (int) data_get($financials, 'diagnostics.eligible_operations_count', 0),
                'Operaciones elegibles', 'Exclusiones del universo general', 'Inclusiones especiales', 'Operaciones mostradas',
                [(int) data_get($financials, 'diagnostics.profitability_excluded_operations_count', 0).' excluidas solo del bloque de rentabilidad']
            ),
            default => $this->bridge(
                (int) data_get($commercials, 'diagnostics.opportunities_total', 0),
                max(0, (int) data_get($commercials, 'diagnostics.opportunities_total', 0)
                    - (int) data_get($commercials, 'diagnostics.operations_count', 0)),
                0,
                (int) data_get($commercials, 'diagnostics.operations_count', 0),
                'Base comun del mes', 'Exclusiones de Comerciales', 'Inclusiones especiales', 'Operaciones mostradas'
            ),
        };
    }

    private function bridge(
        int $base,
        int $excluded,
        int $included,
        int $shown,
        string $baseLabel,
        string $excludedLabel,
        string $includedLabel,
        string $shownLabel,
        array $notes = [],
    ): array {
        return compact('base', 'excluded', 'included', 'shown', 'baseLabel', 'excludedLabel', 'includedLabel', 'shownLabel', 'notes');
    }

    public function exportCallCenterMissingCaptadorCsv(
        Request $request,
        CallCenterCommissionDashboardService $callCenterDashboard,
    ) {
        abort_unless(ReportUserAccess::isAdmin($request) || ReportUserAccess::isDirector($request), 403);

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

    public function exportDelegationDeliveriesCsv(
        Request $request,
        CommercialCommissionDashboardService $dashboard,
        AreaRestrictedCommissionScope $areaScope,
    ) {
        $role = ReportUserAccess::role($request);
        abort_unless(in_array($role, [
            ReportUser::ROLE_ADMIN,
            ReportUser::ROLE_DIRECTOR,
            ReportUser::ROLE_AREA_MANAGER,
            ReportUser::ROLE_DELEGATION_MANAGER,
        ], true), 403);
        $audit = $dashboard->delegationDeliveriesAudit($request->query('month'));

        abort_unless($audit['ready'], 409, implode(' | ', $audit['issues'] ?? ['No se pudo preparar la auditoria.']));

        if (ReportUserAccess::isAreaManager($request)) {
            $zoneLabel = ReportUserAccess::areaZoneLabel($request);
            abort_if($zoneLabel === null, 403, 'El usuario no tiene una zona configurada.');
            $audit['rows'] = $areaScope->delegationAuditRows($audit['rows'] ?? [], $zoneLabel);
        }
        if ($role === ReportUser::ROLE_DELEGATION_MANAGER) {
            $delegation = ReportUserAccess::delegationName($request);
            abort_if($delegation === null, 403);
            $audit['rows'] = $areaScope->delegationAuditRowsByDelegation($audit['rows'] ?? [], $delegation);
        }

        $headers = [
            'Opportunity ID',
            'Opportunity Name',
            'Fecha firma contrato',
            'Record Type',
            'Stage',
            'Contrato CV firmado',
            'Motivo de inclusion',
            'Es Facilitea',
            'Owner ID',
            'Owner',
            'Owner activo',
            'Delegacion owner actual',
            'Delegacion owner informe',
            'Tienda entrega-compra',
            'Delegacion calculada',
            'Gestion de venta',
            'Cuenta',
            'Matricula',
            'Vehiculo interes ID',
        ];
        $filename = 'auditoria-entregas-delegaciones-'.$audit['month'].'.csv';

        return response()->streamDownload(function () use ($audit, $headers): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);

            foreach ($audit['rows'] as $row) {
                fputcsv($output, [
                    $row['opportunity_id'],
                    $row['opportunity_name'],
                    $row['contract_signed_date'],
                    $row['record_type_name'],
                    $row['stage_name'],
                    $row['cv_signed'] ? 'Si' : 'No',
                    $row['inclusion_reason'],
                    $row['is_facilitea'] ? 'Si' : 'No',
                    $row['owner_id'],
                    $row['owner_name'],
                    $row['owner_is_active'] ? 'Si' : 'No',
                    $row['owner_delegation'],
                    $row['report_owner_delegation'],
                    $row['delivery_store'],
                    $row['delegation_calculated'],
                    is_null($row['sale_management']) ? '' : ($row['sale_management'] ? 'Si' : 'No'),
                    $row['account_name'],
                    $row['vehicle_plate'],
                    $row['vehicle_interest_id'],
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportReviewsAuditCsv(
        Request $request,
        CommercialCommissionDashboardService $dashboard,
        CommercialCommissionClosureService $closures,
    ) {
        abort_unless(ReportUserAccess::canAudit($request), 403);
        $month = $request->query('month');
        $snapshot = $closures->definitiveSnapshot($month, 'commercials');
        $audit = $snapshot['review_audit'] ?? $dashboard->reviewAudit($month);
        $filename = 'auditoria-resenas-'.$audit['month'].'.csv';

        return response()->streamDownload(function () use ($audit): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Regla', $audit['rule']], ';');
            fputcsv($output, [
                'Review ID', 'CreatedDate', 'Owner ID', 'Owner', 'Opportunity ID', 'Opportunity',
                'Opportunity Owner ID', 'Opportunity Owner', 'Record Type', 'Fecha firma', 'Motivo', 'Fuente',
            ], ';');
            foreach ($audit['rows'] as $row) {
                fputcsv($output, [
                    $row['review_id'], $row['created_date'], $row['owner_id'], $row['owner_name'],
                    $row['opportunity_id'], $row['opportunity_name'], $row['opportunity_owner_id'],
                    $row['opportunity_owner_name'], $row['opportunity_record_type'],
                    $row['opportunity_signed_date'], $row['inclusion_reason'], $row['source'],
                ], ';');
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportCommissionsXlsx(
        Request $request,
        CommercialCommissionDashboardService $dashboard,
        CallCenterCommissionDashboardService $callCenterDashboard,
        ContactCenterCommissionDashboardService $contactCenterDashboard,
        AreaManagerCommissionDashboardService $areaManagerDashboard,
        FinancialCommissionDashboardService $financialDashboard,
        SimpleXlsxWorkbookWriter $workbookWriter,
        AreaRestrictedCommissionScope $areaScope,
        CommercialCommissionClosureService $closureService,
    ) {
        abort_unless(ReportUserAccess::canViewCommercialCommissions($request), 403);
        $role = ReportUserAccess::role($request);
        $isAreaRestricted = ReportUserAccess::isAreaManager($request);
        $areaZoneLabel = ReportUserAccess::areaZoneLabel($request);
        abort_if($isAreaRestricted && $areaZoneLabel === null, 403, 'El usuario no tiene una zona configurada.');

        try {
            // The export evaluates six independent dashboards. Keep only one payload
            // in memory at a time so the audit detail arrays cannot exhaust PHP.
            @ini_set('memory_limit', '512M');
            @set_time_limit(120);

            $sheets = [];
            if ($role === ReportUser::ROLE_FINANCIAL) {
                $financialPayload = $financialDashboard->build($request->query('month'));
                $month = $financialPayload['month'];
                $sheets[] = $this->commissionSheet(
                    'Financieros',
                    'Responsable/Zona financiera',
                    $financialPayload['summary_rows'] ?? [],
                    'summary_label',
                    'final_commission',
                );
                $path = $workbookWriter->write($sheets);

                return response()
                    ->download($path, 'comisiones-'.$month.'-financieros.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->deleteFileAfterSend(true);
            }
            $commercialDashboard = $dashboard->build(
                $request->query('month'),
                includeSummaryRows: true,
                includeDelegationRows: true,
                includeDetails: false,
            );
            $commercialSnapshot = $closureService->definitiveSnapshot($commercialDashboard['month'], 'commercials');
            $delegationSnapshot = $closureService->definitiveSnapshot($commercialDashboard['month'], 'delegations');
            if ($commercialSnapshot !== null) {
                $commercialDashboard = array_replace($commercialDashboard, $commercialSnapshot['commercials']);
            }
            if ($delegationSnapshot !== null) {
                $commercialDashboard['delegation_rows'] = data_get($delegationSnapshot, 'delegations.delegation_rows', []);
            }
            if ($isAreaRestricted) {
                $commercialDashboard = $areaScope->commercialDashboard(
                    $commercialDashboard,
                    $areaZoneLabel,
                    ReportUserAccess::current($request)['email'] ?? null,
                );
            }
            if ($role === ReportUser::ROLE_DELEGATION_MANAGER) {
                $delegation = ReportUserAccess::delegationName($request);
                abort_if($delegation === null, 403);
                $commercialDashboard = $areaScope->delegationDashboard($commercialDashboard, $delegation);
            }
            if ($role === ReportUser::ROLE_COMMERCIAL) {
                $salesforceUserId = ReportUserAccess::salesforceUserId($request);
                abort_if($salesforceUserId === null, 403);
                $commercialDashboard = $areaScope->commercialDashboardByUser($commercialDashboard, $salesforceUserId);
            }
            $month = $commercialDashboard['month'];
            $sheets[] = $this->commissionSheet(
                'Comerciales',
                'Comercial',
                $commercialDashboard['summary_rows'] ?? [],
                'commercial_name',
                'final_commission',
            );
            $sheets[] = $this->commissionSheet(
                'Delegaciones',
                'Delegacion',
                $commercialDashboard['delegation_rows'] ?? [],
                'delegation_name',
                'total_commission',
            );
            unset($commercialDashboard);
            gc_collect_cycles();

            if ($isAreaRestricted) {
                $areaManagerSnapshot = $closureService->definitiveSnapshot($month, 'area_manager');
                $areaManagerPayload = data_get($areaManagerSnapshot, 'area_manager_by_zone.'.ReportUserAccess::areaZoneKey($request))
                    ?? $areaManagerDashboard->build($month, $areaZoneLabel);
                $areaManagerRows = collect($areaManagerPayload['summary_rows'] ?? []);
                $sheets[] = $this->commissionSheet(
                    'Area Managers',
                    'Area Manager',
                    $areaManagerRows->all(),
                    'manager_name',
                    'final_total',
                );

                unset($areaManagerPayload, $areaManagerRows);
                gc_collect_cycles();

                $path = $workbookWriter->write($sheets);

                return response()
                    ->download($path, 'comisiones-'.$month.'-'.str_replace(' ', '-', mb_strtolower($areaZoneLabel)).'.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->deleteFileAfterSend(true);
            }

            if (in_array($role, [ReportUser::ROLE_DELEGATION_MANAGER, ReportUser::ROLE_COMMERCIAL], true)) {
                $path = $workbookWriter->write($sheets);

                return response()
                    ->download($path, 'comisiones-'.$month.'-'.$role.'.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->deleteFileAfterSend(true);
            }

            $callCenterPayload = $callCenterDashboard->build(
                $month,
                is_string($request->query('call_center_contract_from')) ? $request->query('call_center_contract_from') : null,
                is_string($request->query('call_center_contract_to')) ? $request->query('call_center_contract_to') : null,
                includeDetails: false,
            );
            $sheets[] = $this->commissionSheet(
                'Call Center',
                'Agente / captador',
                $callCenterPayload['summary_rows'] ?? [],
                'agent_name',
                'final_total',
            );
            unset($callCenterPayload);
            gc_collect_cycles();

            $contactCenterPayload = $contactCenterDashboard->build($month, includeDetails: false);
            $sheets[] = $this->commissionSheet(
                'Contact Center',
                'Agente / captador',
                $contactCenterPayload['summary_rows'] ?? [],
                'agent_name',
                'final_total',
            );
            unset($contactCenterPayload);
            gc_collect_cycles();

            $areaManagerSnapshot = $closureService->definitiveSnapshot($month, 'area_manager');
            $areaManagerPayload = $areaManagerSnapshot['area_manager'] ?? $areaManagerDashboard->build($month);
            $areaManagerRows = collect($areaManagerPayload['summary_rows'] ?? []);
            $sheets[] = $this->commissionSheet(
                'Area Managers',
                'Area Manager',
                $areaManagerRows->all(),
                'manager_name',
                'final_total',
            );
            unset($areaManagerPayload, $areaManagerRows);
            gc_collect_cycles();

            $financialPayload = $financialDashboard->build($month);
            $sheets[] = $this->commissionSheet(
                'Financieros',
                'Responsable/Zona financiera',
                $financialPayload['summary_rows'] ?? [],
                'summary_label',
                'final_commission',
            );
            unset($financialPayload);
            gc_collect_cycles();

            $path = $workbookWriter->write($sheets);

            return response()
                ->download($path, 'comisiones-'.$month.'.xlsx', [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {
            Log::error('No se pudo exportar el XLSX de comisiones.', [
                'month' => $request->query('month'),
                'user_id' => ReportUserAccess::current($request)['id'] ?? null,
                'exception' => $exception,
            ]);

            return redirect()
                ->route('reports.commercial-commissions.index', array_filter([
                    'month' => $request->query('month'),
                    'tab' => 'summary',
                ]))
                ->withErrors(['export' => 'No se pudo generar el Excel de comisiones. El motivo se ha registrado para revision tecnica.']);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{name: string, headers: array<int, string>, rows: array<int, array<int, string|float>>}
     */
    private function commissionSheet(string $name, string $entityLabel, array $rows, string $nameKey, string $commissionKey): array
    {
        return [
            'name' => $name,
            'headers' => [$entityLabel, 'Comision final'],
            'rows' => collect($rows)
                ->map(fn (array $row): array => [
                    (string) ($row[$nameKey] ?? '-'),
                    round((float) ($row[$commissionKey] ?? 0), 2),
                ])
                ->values()
                ->all(),
        ];
    }

    private function resolveActiveTab(mixed $value, bool $isAreaRestricted = false): string
    {
        if ($value === 'detail') {
            return 'summary';
        }

        $allowedTabs = $isAreaRestricted
            ? ['summary', 'delegations', 'area-manager']
            : ['summary', 'delegations', 'call-center', 'contact-center', 'area-manager', 'financials'];

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
