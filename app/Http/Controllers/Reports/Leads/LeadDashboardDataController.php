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
    ) {}

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
        abort_unless(ReportUserAccess::canAudit($request), 403);

        return response()->json($this->dataset->kpiAudit($request));
    }

    public function leadAudit(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canAudit($request), 403);

        $ids = $request->input('ids', []);
        $ids = is_array($ids) ? $ids : preg_split('/[\s,;]+/', (string) $ids, -1, PREG_SPLIT_NO_EMPTY);

        return response()->json($this->dataset->leadAudit(array_slice($ids ?: [], 0, 200)));
    }

    public function exportKpiAuditCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canAudit($request), 403);

        $payload = $this->dataset->kpiAudit($request);
        $rows = $payload['items'] ?? [];
        $metric = $payload['metric'] ?? 'leads_totales';
        $headers = [
            'Metrica',
            'Lead ID',
            'Lead name',
            'Created date',
            'Status',
            'RecordType bruto',
            'RecordType normalizado',
            'Portal resuelto',
            'Campo resolucion portal',
            'Portal_Text__c bruto',
            'Grupo portal',
            'Canal',
            'Delegacion bruta',
            'Delegacion normalizada',
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
            'LEA_SEL_Fuente_Origen__c bruto',
            'Medio origen',
            'Fuente_Nuevo__c bruto',
            'Medio_Nuevo__c bruto',
            'Vehicle interest',
            'Converted account ID',
            'Converted opportunity ID',
            'Salesforce LastModifiedDate',
            'Sincronizado en local',
            'Eliminado en Salesforce',
            'Fecha eliminacion Salesforce',
            'Origen deteccion eliminacion',
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
                    $row['lead_type_raw'] ?? $row['lead_type'] ?? null,
                    $row['lead_type_normalized'] ?? null,
                    $row['portal'] ?? null,
                    $row['portal_resolution_source'] ?? null,
                    $row['portal_text'] ?? null,
                    $row['portal_group'] ?? null,
                    $row['channel'] ?? null,
                    $row['lead_delegation_raw'] ?? null,
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
                    $row['salesforce_last_modified_at'] ?? null,
                    $row['synced_at'] ?? null,
                    ($row['is_deleted'] ?? false) ? 'Si' : 'No',
                    $row['salesforce_deleted_at'] ?? null,
                    $row['deletion_detection_source'] ?? null,
                ]);
            }

            fclose($output);
        }, "leads-auditoria-{$metric}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportReconciliationAuditCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canAudit($request), 403);

        $rows = $this->dataset->reconciliationAudit($request);
        $headers = $rows === [] ? ['Lead ID'] : array_keys($rows[0]);

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            foreach ($rows as $row) {
                fputcsv($output, array_map(fn ($value) => is_bool($value) ? ($value ? 'Si' : 'No') : $value, $row));
            }
            fclose($output);
        }, 'leads-conciliacion-activos-eliminados.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
