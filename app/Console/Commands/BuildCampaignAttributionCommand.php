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

        $this->line('Leads procesados: '.$result['processed_leads']);
        $this->line('Atribuciones guardadas: '.$result['saved_attributions']);
        $this->line('Cruces con plataforma: '.$result['matched_to_platform']);
        $this->line('Oportunidades: '.$result['opportunities']);
        $this->line('Reservas: '.$result['reservations']);
        $this->line('Reservas caidas: '.$result['fallen_reservations']);
        $this->line('Ventas: '.$result['sales']);

        return self::SUCCESS;
    }
}
