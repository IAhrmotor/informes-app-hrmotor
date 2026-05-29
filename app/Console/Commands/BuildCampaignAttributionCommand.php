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
        $this->line('Match ad_id: '.$result['match_ad_id']);
        $this->line('Match adset/ad_group: '.$result['match_adset_or_adgroup']);
        $this->line('Match campaign_id: '.$result['match_campaign_id']);
        $this->line('Match campaign_name: '.$result['match_campaign_name']);
        $this->line('Sin plataforma asociada: '.$result['salesforce_only']);
        $this->line('Oportunidades: '.$result['opportunities']);
        $this->line('Reservas: '.$result['reservations']);
        $this->line('Reservas caidas: '.$result['fallen_reservations']);
        $this->line('Ventas: '.$result['sales']);
        $this->line('Tiempo total: '.$result['duration_seconds'].'s');
        $this->line('Memoria pico: '.$result['peak_memory_mb'].' MB');

        return self::SUCCESS;
    }
}
