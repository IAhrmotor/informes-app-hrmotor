<?php

namespace App\Http\Controllers\Reports\Campaigns;

use App\Http\Controllers\Controller;
use App\Services\Campaigns\CampaignDashboardDatasetService;
use App\Support\ReportUserAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignDashboardDataController extends Controller
{
    public function __construct(
        private readonly CampaignDashboardDatasetService $dataset,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->dataset->summary($request));
    }

    public function campaigns(Request $request): JsonResponse
    {
        return response()->json($this->dataset->campaignRows($request));
    }

    public function rankings(Request $request): JsonResponse
    {
        return response()->json($this->dataset->rankings($request));
    }

    public function exportCampaignsCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canExport($request), 403);

        $rows = $this->dataset->exportRows($request);
        $headers = [
            'Plataforma',
            'Cuenta',
            'Fuente adquirida',
            'Medio adquirido',
            'Campana adquirida',
            'ID adquirido',
            'Contenido adquirido',
            'Campaign ID',
            'Campaign name',
            'Inversion',
            'Impresiones',
            'Clicks',
            'Leads Salesforce',
            'Oportunidades',
            'Reservas',
            'Reservas vivas',
            'Reservas caidas',
            'Ventas',
            'Importe vendido',
            'Tasaciones generadas',
            'Compras contratos firmados',
            'Coste por tasacion',
            'Coste por compra',
            'Coste por lead',
            'Coste por oportunidad',
            'Coste por reserva',
            'Coste por venta',
            'ROAS',
            'ROI estimado',
            'Clasificacion',
            'Estado campana',
            'Fecha inicio campana',
            'Fecha fin campana',
            'Ultima fecha con inversion',
        ];

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['platform'],
                    $row['account_id'],
                    $row['source_acquired'],
                    $row['medium_acquired'],
                    $row['campaign_acquired'],
                    $row['acquired_id'],
                    $row['content_acquired'],
                    $row['campaign_id'],
                    $row['campaign_name'],
                    $row['spend'],
                    $row['impressions'],
                    $row['clicks'],
                    $row['leads_salesforce'],
                    $row['opportunities'],
                    $row['reservations'],
                    $row['live_reservations'],
                    $row['fallen_reservations'],
                    $row['sales'],
                    $row['sale_amount'],
                    $row['appraisals_generated'] ?? 0,
                    $row['purchases'] ?? 0,
                    $row['cost_per_appraisal'] ?? null,
                    $row['cost_per_purchase'] ?? null,
                    $row['cost_per_lead'],
                    $row['cost_per_opportunity'],
                    $row['cost_per_reservation'],
                    $row['cost_per_sale'],
                    $row['roas'],
                    $row['estimated_roi'],
                    $row['classification'],
                    $row['campaign_status_label'] ?? null,
                    $row['campaign_start_date'] ?? null,
                    $row['campaign_end_date'] ?? null,
                    $row['last_spend_date'] ?? null,
                ]);
            }

            fclose($output);
        }, 'campanas.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
