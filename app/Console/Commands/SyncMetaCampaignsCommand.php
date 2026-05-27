<?php

namespace App\Console\Commands;

use App\Services\Campaigns\MetaCampaignSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncMetaCampaignsCommand extends Command
{
    protected $signature = 'campaigns:sync-meta {--days=60 : Dias hacia atras que se sincronizan}';

    protected $description = 'Sincroniza metricas diarias de Meta Ads para el informe de campanas.';

    public function handle(MetaCampaignSyncService $sync): int
    {
        $days = max((int) $this->option('days'), 1);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days)->startOfDay();

        $result = $sync->sync($start, $end);

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $this->line('Meta Ads configurado: '.($result['configured'] ? 'si' : 'no'));
        $this->line('Filas procesadas: '.$result['processed']);
        $this->line('Filas guardadas: '.$result['saved']);

        return self::SUCCESS;
    }
}
