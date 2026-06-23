<?php

namespace App\Http\Controllers\Reports\ReservationsSales;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReservationsSales\ReservationsSalesDashboardDatasetService;
use App\Support\ReportUserAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationsSalesDashboardDataController extends Controller
{
    public function __construct(
        private readonly ReservationsSalesDashboardDatasetService $dataset,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->dataset->summary($request));
    }

    public function commercials(Request $request): JsonResponse
    {
        return response()->json($this->dataset->commercialRows($request));
    }

    public function portals(Request $request): JsonResponse
    {
        return response()->json($this->dataset->portalRows($request));
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
        $metric = $payload['metric'] ?? 'oportunidades_totales';
        $headers = [
            'Metrica',
            'Opportunity ID',
            'Opportunity name',
            'Fecha metrica',
            'Created date',
            'Close date',
            'Reservation date',
            'CV signed date',
            'Record type',
            'Stage',
            'Owner ID',
            'Owner name',
            'Delegacion comercial',
            'Zona comercial',
            'Account ID',
            'Account name',
            'Account phone',
            'Account person email',
            'Account company email',
            'Portal original',
            'Portal resuelto',
            'Origen resolucion portal',
            'Lead resolucion portal',
            'Fuente oportunidad raw',
            'Fuente oportunidad normalizada',
            'Reserva viva',
            'Oportunidad caida',
            'CV firmado',
        ];

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['metric_label'] ?? null,
                    $row['opportunity_id'] ?? null,
                    $row['opportunity_name'] ?? null,
                    $row['metric_date'] ?? null,
                    $row['created_date'] ?? null,
                    $row['close_date'] ?? null,
                    $row['reservation_date'] ?? null,
                    $row['cv_signed_date'] ?? null,
                    $row['record_type_name'] ?? null,
                    $row['stage_name'] ?? null,
                    $row['owner_id'] ?? null,
                    $row['owner_name'] ?? null,
                    $row['commercial_delegation'] ?? null,
                    $row['zone'] ?? null,
                    $row['account_id'] ?? null,
                    $row['account_name'] ?? null,
                    $row['account_phone'] ?? null,
                    $row['account_person_email'] ?? null,
                    $row['account_company_email'] ?? null,
                    $row['portal_original'] ?? null,
                    $row['portal_resolved'] ?? null,
                    $row['portal_resolution_source'] ?? null,
                    $row['portal_resolution_lead_id'] ?? null,
                    $row['opportunity_source_raw'] ?? null,
                    $row['opportunity_source_normalized'] ?? null,
                    ($row['is_reserva_viva'] ?? false) ? '1' : '0',
                    ($row['is_caida'] ?? false) ? '1' : '0',
                    ($row['is_cv_firmado'] ?? false) ? '1' : '0',
                ]);
            }

            fclose($output);
        }, "reservas-ventas-auditoria-{$metric}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
