<?php

namespace App\Console\Commands;

use App\Services\Campaigns\GoogleCampaignSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncGoogleCampaignsCommand extends Command
{
    protected $signature = 'campaigns:sync-google {--days=60 : Dias hacia atras que se sincronizan}';

    protected $description = 'Sincroniza metricas diarias de Google Ads para el informe de campanas.';

    public function handle(GoogleCampaignSyncService $sync): int
    {
        $days = max((int) $this->option('days'), 1);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days)->startOfDay();

        $result = $sync->sync($start, $end);

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $this->line('Google Ads configurado: '.($result['configured'] ? 'si' : 'no'));
        $this->line('Filas procesadas: '.$result['processed']);
        $this->line('Filas guardadas: '.$result['saved']);

        return self::SUCCESS;
    }
}
