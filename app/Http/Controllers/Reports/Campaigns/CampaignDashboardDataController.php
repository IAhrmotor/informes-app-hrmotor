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
    private const JSON_RESPONSE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private readonly CampaignDashboardDatasetService $dataset,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canViewCampaigns($request), 403);

        return $this->jsonResponse($this->dataset->summary($request));
    }

    public function campaigns(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canViewCampaigns($request), 403);

        return $this->jsonResponse($this->dataset->campaignRows($request));
    }

    public function rankings(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canViewCampaigns($request), 403);

        return $this->jsonResponse($this->dataset->rankings($request));
    }

    public function kpiAudit(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'campaigns'), 403);

        return $this->jsonResponse($this->dataset->kpiAudit($request));
    }

    public function attributionAudit(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'campaigns'), 403);

        $items = $this->dataset->attributionAuditRows($request);

        return $this->jsonResponse(['ok' => true, 'total' => count($items), 'items' => $items]);
    }

    public function exportCampaignsCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canExportReport($request, 'campaigns'), 403);

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

    public function exportKpiAuditCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'campaigns'), 403);

        $payload = $this->dataset->kpiAudit($request);
        $rows = $payload['items'] ?? [];
        $metric = $payload['metric'] ?? 'result_count';
        $headers = [
            'Metrica',
            'Fecha metrica',
            'Tipo entidad',
            'Entity ID',
            'Lead IDs',
            'Lead names',
            'Lead created dates',
            'Lead statuses',
            'Lead portals',
            'Lead fuente origen',
            'Lead medio origen',
            'Lead owner names',
            'Opportunity IDs',
            'Opportunity names',
            'Opportunity created dates',
            'Opportunity close dates',
            'CV signed dates',
            'Opportunity record types',
            'Opportunity stages',
            'Opportunity owner names',
            'Account IDs',
            'Account names',
            'Opportunity portals',
            'Opportunity sources',
            'Platforms',
            'Campaign IDs',
            'Campaign names',
            'Source campaign names',
            'Source acquired',
            'Medium acquired',
            'Campaign acquired',
            'Acquired IDs',
            'Content acquired',
            'Commercial user IDs',
            'Commercial user names',
            'Lead delegations',
            'Lead zones',
            'Vehicle interests',
            'Sale amount',
            'Purchase amount',
        ];

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['metric_label'] ?? null,
                    $row['metric_date'] ?? null,
                    $row['entity_type'] ?? null,
                    $row['entity_id'] ?? null,
                    $this->implodeAuditValues($row['lead_ids'] ?? []),
                    $this->implodeAuditValues($row['lead_names'] ?? []),
                    $this->implodeAuditValues($row['lead_created_dates'] ?? []),
                    $this->implodeAuditValues($row['lead_statuses'] ?? []),
                    $this->implodeAuditValues($row['lead_portals'] ?? []),
                    $this->implodeAuditValues($row['lead_source_origins'] ?? []),
                    $this->implodeAuditValues($row['lead_medium_origins'] ?? []),
                    $this->implodeAuditValues($row['lead_owner_names'] ?? []),
                    $this->implodeAuditValues($row['opportunity_ids'] ?? []),
                    $this->implodeAuditValues($row['opportunity_names'] ?? []),
                    $this->implodeAuditValues($row['opportunity_created_dates'] ?? []),
                    $this->implodeAuditValues($row['opportunity_close_dates'] ?? []),
                    $this->implodeAuditValues($row['cv_signed_dates'] ?? []),
                    $this->implodeAuditValues($row['opportunity_record_types'] ?? []),
                    $this->implodeAuditValues($row['opportunity_stages'] ?? []),
                    $this->implodeAuditValues($row['opportunity_owner_names'] ?? []),
                    $this->implodeAuditValues($row['account_ids'] ?? []),
                    $this->implodeAuditValues($row['account_names'] ?? []),
                    $this->implodeAuditValues($row['opportunity_portals'] ?? []),
                    $this->implodeAuditValues($row['opportunity_sources'] ?? []),
                    $this->implodeAuditValues($row['platforms'] ?? []),
                    $this->implodeAuditValues($row['campaign_ids'] ?? []),
                    $this->implodeAuditValues($row['campaign_names'] ?? []),
                    $this->implodeAuditValues($row['source_campaign_names'] ?? []),
                    $this->implodeAuditValues($row['source_acquired_values'] ?? []),
                    $this->implodeAuditValues($row['medium_acquired_values'] ?? []),
                    $this->implodeAuditValues($row['campaign_acquired_values'] ?? []),
                    $this->implodeAuditValues($row['acquired_ids'] ?? []),
                    $this->implodeAuditValues($row['content_acquired_values'] ?? []),
                    $this->implodeAuditValues($row['commercial_user_ids'] ?? []),
                    $this->implodeAuditValues($row['commercial_user_names'] ?? []),
                    $this->implodeAuditValues($row['lead_delegations'] ?? []),
                    $this->implodeAuditValues($row['lead_zones'] ?? []),
                    $this->implodeAuditValues($row['vehicle_interests'] ?? []),
                    $row['sale_amount'] ?? null,
                    $row['purchase_amount'] ?? null,
                ]);
            }

            fclose($output);
        }, "campanas-auditoria-{$metric}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportAttributionsCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'campaigns'), 403);

        $rows = $this->dataset->attributionAuditRows($request);
        $headers = [
            'Attribution ID',
            'Lead.Id',
            'Campaña final',
            'Campaña bruta Salesforce',
            'Plataforma',
            'ID anuncio usado',
            'ID adset/ad group usado',
            'ID campaña final',
            'Id_Adquirido__c bruto',
            'Contenido_Adquirido__c bruto',
            'Tipo de match',
            'Confianza del match',
            'Estado del match',
            'Campo Salesforce que hizo match',
            'Valor Salesforce que hizo match',
            'Campo plataforma que hizo match',
            'Valor plataforma que hizo match',
            'Numero de candidatos',
            'Candidatos considerados',
            'Primer contacto conocido',
            'Atribucion ambigua',
            'Version de reglas',
            'Origen de campaña',
            'Tipo de campaña',
            'Motivo de exclusion',
            'Mecanismo de exclusion',
            'Valor de exclusion',
            'RecordType Lead bruto',
            'RecordType Lead normalizado',
            'CreatedDate Lead',
            'Opportunity relacionada',
            'Atribución construida',
            'Atribución actualizada',
            'LastModifiedDate Lead',
            'Lead synced_at',
            'Lead eliminado',
            'Campañas distintas para el Lead',
            'Solapa otra campaña',
        ];

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['attribution_id'],
                    $row['lead_id'],
                    $row['resolved_campaign_name'],
                    $row['raw_campaign_name'],
                    $row['platform'],
                    $row['ad_id'],
                    $row['adset_or_adgroup_id'],
                    $row['campaign_id'],
                    $row['raw_acquired_id'],
                    $row['raw_content_id'],
                    $row['match_type'],
                    $row['match_confidence'],
                    $row['match_status'],
                    $row['matched_source_field'],
                    $row['matched_source_value'],
                    $row['matched_platform_field'],
                    $row['matched_platform_value'],
                    $row['match_candidate_count'],
                    json_encode($row['attribution_candidates'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $row['first_touch_at'],
                    $row['is_ambiguous'] ? 'Si' : 'No',
                    $row['attribution_rule_version'],
                    $row['campaign_source_type'],
                    $row['exclusion_reason'],
                    $row['exclusion_match_type'],
                    $row['exclusion_match_value'],
                    $row['campaign_type'],
                    $row['lead_record_type_raw'],
                    $row['lead_record_type_normalized'],
                    $row['lead_created_date'],
                    $row['opportunity_id'],
                    $row['attribution_built_at'],
                    $row['attribution_updated_at'],
                    $row['lead_last_modified_at'],
                    $row['lead_synced_at'],
                    $row['lead_is_deleted'] ? 'Sí' : 'No',
                    $row['campaigns_for_lead'],
                    $row['overlaps_another_campaign'] ? 'Sí' : 'No',
                ]);
            }

            fclose($output);
        }, 'campanas-atribuciones-auditoria.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function jsonResponse(array $payload): JsonResponse
    {
        return response()->json($payload, 200, [], self::JSON_RESPONSE_FLAGS);
    }

    private function implodeAuditValues(array $values): ?string
    {
        $values = array_values(array_filter($values, fn ($value) => $value !== null && $value !== ''));

        return $values !== [] ? implode(' | ', array_map('strval', $values)) : null;
    }
}
