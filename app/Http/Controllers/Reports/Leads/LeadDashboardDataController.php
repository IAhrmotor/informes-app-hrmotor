<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Services\Reports\Leads\SalesforceLeadDashboardDatasetService;
use App\Support\ReportUserAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadDashboardDataController extends Controller
{
    public function __construct(
        private readonly SalesforceLeadDashboardDatasetService $dataset,
    ) {
    }

    public function resumen(Request $request): JsonResponse
    {
        return response()->json($this->dataset->summary($request));
    }

    public function kpis(Request $request): JsonResponse
    {
        return $this->resumen($request);
    }

    public function portales(Request $request): JsonResponse
    {
        return response()->json($this->dataset->portalRows($request));
    }

    public function portalDetalle(Request $request): JsonResponse
    {
        return $this->portales($request);
    }

    public function delegaciones(Request $request): JsonResponse
    {
        return response()->json($this->dataset->delegationRows($request));
    }

    public function comerciales(Request $request): JsonResponse
    {
        return response()->json($this->dataset->commercialRows($request));
    }

    public function comparativa(Request $request): JsonResponse
    {
        return $this->resumen($request);
    }

    public function calidadDato(Request $request): JsonResponse
    {
        return response()->json([
            'items' => [],
            'message' => 'La calidad de dato CSV no se muestra en la fase Salesforce del dashboard.',
        ]);
    }

    public function kpiAudit(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canExport($request), 403);

        return response()->json($this->dataset->kpiAudit($request));
    }

    public function exportKpiAuditCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canExport($request), 403);

        $payload = $this->dataset->kpiAudit($request);
        $rows = $payload['items'] ?? [];
        $metric = $payload['metric'] ?? 'leads_totales';
        $headers = [
            'Metrica',
            'Lead ID',
            'Lead name',
            'Created date',
            'Status',
            'Lead type',
            'Portal',
            'Grupo portal',
            'Canal',
            'Delegacion lead',
            'Zona lead',
            'Delegacion comercial',
            'Zona comercial',
            'Gestor ID',
            'Gestor nombre',
            'Owner ID',
            'Owner name',
            'Persona trabajo ID',
            'Persona trabajo nombre',
            'Propietario descarte ID',
            'Propietario descarte nombre',
            'Phone',
            'Mobile phone',
            'Email',
            'Campaign acquired',
            'Acquired ID',
            'Content acquired',
            'Fuente origen',
            'Medio origen',
            'Fuente nuevo',
            'Medio nuevo',
            'Vehicle interest',
            'Converted account ID',
            'Converted opportunity ID',
        ];

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['metric_label'] ?? null,
                    $row['lead_id'] ?? null,
                    $row['lead_name'] ?? null,
                    $row['created_date'] ?? null,
                    $row['status'] ?? null,
                    $row['lead_type'] ?? null,
                    $row['portal'] ?? null,
                    $row['portal_group'] ?? null,
                    $row['channel'] ?? null,
                    $row['lead_delegation'] ?? null,
                    $row['lead_zone'] ?? null,
                    $row['commercial_delegation'] ?? null,
                    $row['commercial_zone'] ?? null,
                    $row['gestor_id'] ?? null,
                    $row['gestor_nombre'] ?? null,
                    $row['owner_id'] ?? null,
                    $row['owner_name'] ?? null,
                    $row['persona_que_trabajo_id'] ?? null,
                    $row['persona_que_trabajo_name'] ?? null,
                    $row['propietario_descarte_id'] ?? null,
                    $row['propietario_descarte_name'] ?? null,
                    $row['phone'] ?? null,
                    $row['mobile_phone'] ?? null,
                    $row['email'] ?? null,
                    $row['campaign_acquired'] ?? null,
                    $row['acquired_id'] ?? null,
                    $row['content_acquired'] ?? null,
                    $row['fuente_origen'] ?? null,
                    $row['medio_origen'] ?? null,
                    $row['fuente_nuevo'] ?? null,
                    $row['medio_nuevo'] ?? null,
                    $row['vehicle_interest'] ?? null,
                    $row['converted_account_id'] ?? null,
                    $row['converted_opportunity_id'] ?? null,
                ]);
            }

            fclose($output);
        }, "leads-auditoria-{$metric}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
