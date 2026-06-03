<?php

namespace App\Console\Commands;

use App\Services\Campaigns\GoogleCampaignSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncGoogleCampaignsCommand extends Command
{
    protected $signature = 'campaigns:sync-google
        {--days=60 : Dias hacia atras que se sincronizan}
        {--months= : Meses hacia atras que se sincronizan; tiene prioridad sobre --days}
        {--from= : Fecha inicial explicita en formato Y-m-d}';

    protected $description = 'Sincroniza metricas diarias de Google Ads para el informe de campanas.';

    public function handle(GoogleCampaignSyncService $sync): int
    {
        $end = CarbonImmutable::now();
        $start = $this->periodStart($end);

        $result = $sync->sync($start, $end);

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $this->line('Google Ads configurado: '.($result['configured'] ? 'si' : 'no'));
        $this->line('Filas procesadas: '.$result['processed']);
        $this->line('Filas guardadas: '.$result['saved']);

        return self::SUCCESS;
    }

    private function periodStart(CarbonImmutable $end): CarbonImmutable
    {
        $from = $this->option('from');

        if (filled($from)) {
            return CarbonImmutable::parse($from)->startOfDay();
        }

        $months = $this->option('months');

        if ($months !== null && $months !== '') {
            return $end->subMonthsNoOverflow(max((int) $months, 1))->startOfDay();
        }

        return $end->subDays(max((int) $this->option('days'), 1))->startOfDay();
    }
}
