<?php

namespace App\Console\Commands;

use App\Models\SalesforceLead;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyLeadsSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SalesforceSyncCampaignLeadsCommand extends Command
{
    protected $signature = 'salesforce:sync-campaign-leads
        {--days=90 : Numero de dias hacia atras que se sincronizan}
        {--months= : Meses hacia atras que se sincronizan; tiene prioridad sobre --days}
        {--fresh : Borra leads de campana del periodo antes de sincronizar}
        {--debug-soql : Imprime la query SOQL ejecutada}';

    protected $description = 'Sincroniza Leads Salesforce relevantes para el informe de campanas.';

    public function handle(SalesforceMonthlyLeadsSyncService $sync): int
    {
        $end = CarbonImmutable::now();
        $start = $this->periodStart($end);

        if ($this->option('fresh')) {
            $deleted = SalesforceLead::query()
                ->where('created_date', '>=', $start)
                ->where('created_date', '<', $end)
                ->where(function ($query): void {
                    foreach ([
                        'campaign_acquired',
                        'acquired_id',
                        'content_acquired',
                        'fuente_origen',
                        'medio_origen',
                    ] as $field) {
                        $query->orWhere(function ($subQuery) use ($field): void {
                            $subQuery->whereNotNull($field)->where($field, '<>', '');
                        });
                    }
                })
                ->delete();

            $this->warn("Leads de campana del periodo eliminados: {$deleted}");
        }

        $this->info('Sincronizando Salesforce Leads de campana.');
        $this->line('Periodo inicio: '.$start->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->line('Periodo fin: '.$end->utc()->format('Y-m-d\TH:i:s\Z'));

        if ($this->option('debug-soql')) {
            $this->newLine();
            $this->line('SOQL Leads de campana:');
            $this->line($sync->campaignLeadsSoql($start, $end));
            $this->newLine();
        }

        $result = $sync->syncCampaignLeads($start, $end);

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $this->line('Leads consultados: '.$result['queried']);
        $this->line('Leads guardados: '.$result['saved']);

        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
        $this->info('Sincronizacion de Leads de campana completada.');

        return self::SUCCESS;
    }

    private function periodStart(CarbonImmutable $end): CarbonImmutable
    {
        $months = $this->option('months');

        if ($months !== null && $months !== '') {
            return $end->subMonthsNoOverflow(max((int) $months, 1))->startOfDay();
        }

        return $end->subDays(max((int) $this->option('days'), 1))->startOfDay();
    }
}
