<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignAttributionBuilderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BuildCampaignAttributionCommand extends Command
{
    protected $signature = 'campaigns:build-attribution
        {--days=60 : Dias hacia atras de leads a procesar}
        {--window=30 : Ventana de atribucion en dias}';

    protected $description = 'Construye la atribucion lead -> oportunidad -> reserva -> venta por campana.';

    public function handle(CampaignAttributionBuilderService $builder): int
    {
        $days = max((int) $this->option('days'), 1);
        $window = max((int) $this->option('window'), 1);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days)->startOfDay();

        $result = $builder->build($start, $end, $window);

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $this->line('Rango: '.$result['range_start'].' a '.$result['range_end']);
        $this->line('Leads en rango: '.$result['total_leads_in_range']);
        $this->line('Leads con adquisicion no null: '.$result['leads_with_acquisition_not_null']);
        $this->line('Leads candidatos validos: '.$result['candidate_leads']);
        $this->line('Descartados por valores invalidos: '.$result['discarded_invalid_values']);
        $this->line('Descartados por fecha: '.$result['discarded_by_date']);
        $this->line('Leads procesados: '.$result['processed_leads']);
        $this->line('Atribuciones guardadas: '.$result['saved_attributions']);
        $this->line('Cruces con plataforma: '.$result['matched_to_platform']);
        $this->line('Candidatos con campaign_acquired: '.$result['candidates_with_campaign_acquired']);
        $this->line('Candidatos solo source/medium: '.$result['candidates_only_source_medium']);
        $this->line('Candidatos con acquired_id: '.$result['candidates_with_acquired_id']);
        $this->line('Candidatos con content_acquired: '.$result['candidates_with_content_acquired']);
        $this->line('Match ad_id: '.$result['match_ad_id']);
        $this->line('Match adset/ad_group: '.$result['match_adset_or_adgroup']);
        $this->line('Match campaign_id: '.$result['match_campaign_id']);
        $this->line('Match campaign_name exacto: '.$result['match_campaign_name_exact']);
        $this->line('Match campaign_name flexible: '.$result['match_campaign_name_flexible']);
        $this->line('Match campaign_name: '.$result['match_campaign_name']);
        $this->line('Sin plataforma asociada: '.$result['salesforce_only']);
        $this->line('Salesforce-only por campana: '.$result['source_type_salesforce_campaign_without_spend']);
        $this->line('Salesforce-only por procedencia: '.$result['source_type_salesforce_origin']);
        $this->line('Oportunidades: '.$result['opportunities']);
        $this->line('Reservas: '.$result['reservations']);
        $this->line('Reservas caidas: '.$result['fallen_reservations']);
        $this->line('Ventas: '.$result['sales']);
        $this->line('Ventas atribuidas: '.$result['sales']);
        $this->line('Ventas con opportunity encontrada: '.$result['sales_with_opportunity_found']);
        $this->line('Ventas con opo_for_importe_total > 0: '.$result['sales_with_opo_for_importe_total']);
        $this->line('Ventas con amount > 0: '.$result['sales_with_amount']);
        $this->line('Ventas con sale_amount final > 0: '.$result['sales_with_sale_amount']);
        $this->line('Suma sale_amount: '.number_format((float) $result['sale_amount_sum'], 2, '.', ''));
        $this->line('Campo usado para importe: '.$result['sale_amount_field_used']);
        $this->line('Tiempo total: '.$result['duration_seconds'].'s');
        $this->line('Memoria pico: '.$result['peak_memory_mb'].' MB');

        $this->renderTop('Top 20 campaign_acquired', $result['top_campaign_acquired'] ?? []);
        $this->renderTop('Top 20 source_acquired + medium_acquired', $result['top_source_medium'] ?? []);
        $this->renderTop('Top 20 acquired_id', $result['top_acquired_id'] ?? []);
        $this->renderTop('Top 20 content_acquired', $result['top_content_acquired'] ?? []);
        $this->renderTop('Top 20 plataforma por spend', $result['top_platform_spend'] ?? []);

        return self::SUCCESS;
    }

    private function renderTop(string $title, array $rows): void
    {
        $this->newLine();
        $this->line($title);

        if ($rows === []) {
            $this->line('Sin datos');

            return;
        }

        $this->table(array_keys($rows[0]), $rows);
    }
}
