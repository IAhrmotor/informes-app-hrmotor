<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignLeadSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SalesforceSyncCampaignLeadsCommand extends Command
{
    protected $signature = 'salesforce:sync-campaign-leads
        {--days=90 : Numero de dias hacia atras que se sincronizan}
        {--months= : Meses hacia atras que se sincronizan; tiene prioridad sobre --days}
        {--from= : Fecha inicial explicita en formato Y-m-d}
        {--to= : Fecha final exclusiva explicita en formato Y-m-d}
        {--fresh : Borra leads de campana del periodo antes de sincronizar}
        {--debug-soql : Imprime la query SOQL ejecutada}';

    protected $description = 'Sincroniza Leads Salesforce relevantes para el informe de campanas.';

    public function handle(CampaignLeadSyncService $sync): int
    {
        $end = $this->periodEnd();
        $start = $this->periodStart($end);

        if ($end->lessThanOrEqualTo($start)) {
            $this->error('El rango indicado no es valido: --to debe ser posterior a --from.');

            return self::FAILURE;
        }

        $this->info('Sincronizando Salesforce Leads de campana.');
        $this->line('Periodo inicio: '.$start->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->line('Periodo fin exclusivo: '.$end->utc()->format('Y-m-d\TH:i:s\Z'));

        if ($this->option('debug-soql')) {
            $this->newLine();
            $this->line('SOQL Leads de campana:');
            $this->line($sync->soql($start, $end));
            $this->newLine();
        }

        $result = $sync->sync($start, $end, (bool) $this->option('fresh'));

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $this->line('Tabla destino: '.$result['table']);
        $this->line('Leads de campana eliminados en tabla destino: '.$result['deleted']);
        $this->line('Leads recibidos de Salesforce: '.$result['queried']);
        $this->line('Leads guardados/upserted: '.$result['saved']);
        $this->line('Leads con campaign_acquired: '.$result['with_campaign_acquired']);
        $this->line('Leads con acquired_id: '.$result['with_acquired_id']);
        $this->line('Leads con content_acquired: '.$result['with_content_acquired']);
        $this->line('Leads con fuente_origen: '.$result['with_fuente_origen']);
        $this->line('Leads con medio_origen: '.$result['with_medio_origen']);
        $this->line('Leads sin adquisicion/fuente/medio: '.$result['without_acquisition']);

        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
        $this->info('Sincronizacion de Leads de campana completada.');

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

    private function periodEnd(): CarbonImmutable
    {
        $to = $this->option('to');

        if (filled($to)) {
            return CarbonImmutable::parse($to)->startOfDay();
        }

        return CarbonImmutable::now();
    }
}
