<?php

namespace App\Console\Commands;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyUsersSyncService;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SalesforceSyncOpportunitiesCommand extends Command
{
    protected $signature = 'salesforce:sync-opportunities
        {--days=60 : Numero de dias hacia atras que se sincronizan}
        {--fresh : Borra solo la tabla de oportunidades Salesforce antes de sincronizar}
        {--debug-soql : Imprime la query SOQL ejecutada}';

    protected $description = 'Sincroniza Opportunities de Salesforce para el dashboard Reservas / Ventas.';

    public function handle(
        SalesforceMonthlyUsersSyncService $usersSync,
        SalesforceOpportunitySyncService $opportunitiesSync,
    ): int {
        $days = max((int) $this->option('days'), 1);
        $periodEnd = CarbonImmutable::now();
        $periodStart = $periodEnd->subDays($days);

        if ($this->option('fresh')) {
            SalesforceOpportunity::query()->delete();
            $this->warn('Tabla salesforce_opportunities vaciada.');
        }

        try {
            $this->info('Sincronizando Salesforce Reservas / Ventas.');
            $this->line('Periodo inicio: '.$periodStart->utc()->format('Y-m-d\TH:i:s\Z'));
            $this->line('Periodo fin: '.$periodEnd->utc()->format('Y-m-d\TH:i:s\Z'));

            $users = $usersSync->sync();
            $this->line('Usuarios consultados: '.$users['queried']);
            $this->line('Usuarios sincronizados: '.$users['saved']);

            if ($this->option('debug-soql')) {
                $this->newLine();
                $this->line('SOQL Opportunities:');
                $this->line($opportunitiesSync->soql($periodStart, $periodEnd));
                $this->newLine();
            }

            $result = $opportunitiesSync->sync($periodStart, $periodEnd);
            $stats = $result['stats'];

            $this->line('Opportunities consultadas: '.$result['queried']);
            $this->line('Opportunities guardadas: '.$result['saved']);
            $this->line('Con Portal__c util: '.$stats['opportunity']);
            $this->line('Portal reconstruido desde Lead: '.$stats['lead']);
            $this->line('Portal desde Fuente_de_Origen__c: '.$stats['opportunity_source']);
            $this->line('Fallback Exposicion: '.$stats['fallback_exposicion']);
            $this->line('Fallback Web: '.$stats['fallback_web']);
            $this->line('Sin clasificar: '.$stats['unclassified']);
            $this->line('Reservas vivas: '.$stats['reservas_vivas']);
            $this->line('Caidas: '.$stats['caidas']);
            $this->line('CV firmados: '.$stats['cv_firmados']);

            if ($result['queried'] === 0) {
                $this->warn('Salesforce devolvio 0 opportunities para el periodo indicado.');
            }

            $this->invalidateDashboardCache();
            $this->info('Sincronizacion Reservas / Ventas completada.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Error sincronizando Opportunities.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function invalidateDashboardCache(): void
    {
        Cache::forever('reservas_ventas_dashboard_cache_version', ((int) Cache::get('reservas_ventas_dashboard_cache_version', 1)) + 1);
        $this->line('Cache del dashboard Reservas / Ventas invalidada.');
    }
}
