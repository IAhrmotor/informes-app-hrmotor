<?php

namespace App\Console\Commands;

use App\Models\SalesforceActivity;
use App\Models\SalesforceLead;
use App\Models\SalesforceLeadActivitySummary;
use App\Models\SalesforceUser;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceLeadActivitySummaryService;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyActivitiesSyncService;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyLeadsSyncService;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyUsersSyncService;
use App\Services\Reports\ReportSyncRunService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SalesforceSyncMonthlyCommercialCommand extends Command
{
    protected $signature = 'salesforce:sync-monthly-commercial
        {--days=60 : Numero de dias hacia atras que se sincronizan}
        {--from= : Fecha inicial explicita en formato Y-m-d}
        {--to= : Fecha final exclusiva explicita en formato Y-m-d}
        {--fresh : Borra solo las tablas Salesforce mensuales nuevas antes de sincronizar}
        {--debug-soql : Imprime las queries SOQL ejecutadas}';

    protected $description = 'Sincroniza usuarios, leads, Task, Event y summaries para el informe mensual comercial.';

    public function handle(
        SalesforceMonthlyUsersSyncService $usersSync,
        SalesforceMonthlyLeadsSyncService $leadsSync,
        SalesforceMonthlyActivitiesSyncService $activitiesSync,
        SalesforceLeadActivitySummaryService $summaryService,
        ReportSyncRunService $syncRuns,
    ): int {
        @ini_set('memory_limit', '512M');

        $periodEnd = filled($this->option('to'))
            ? CarbonImmutable::parse($this->option('to'))->startOfDay()
            : CarbonImmutable::now();
        $periodStart = filled($this->option('from'))
            ? CarbonImmutable::parse($this->option('from'))->startOfDay()
            : $periodEnd->subDays(max((int) $this->option('days'), 1));

        if ($periodEnd->lessThanOrEqualTo($periodStart)) {
            $this->error('El rango indicado no es valido: --to debe ser posterior a --from.');

            return self::FAILURE;
        }

        $syncRun = $syncRuns->start('leads_dashboard', 'salesforce', $periodStart, $periodEnd);

        try {
            if ($this->option('fresh')) {
                $this->freshNewTables();
            }

            $this->info('Sincronizando Salesforce mensual comercial.');
            $this->line('Periodo inicio: '.$this->soqlDateTime($periodStart));
            $this->line('Periodo fin: '.$this->soqlDateTime($periodEnd));

            if ($this->option('debug-soql')) {
                $this->printSoql('Users', $usersSync->soql());
                $this->printSoql('Leads', $leadsSync->soql($periodStart, $periodEnd));
                $this->printSoql('Tasks', $activitiesSync->tasksSoql($periodStart, $periodEnd));
                $this->printSoql('Events', $activitiesSync->eventsSoql($periodStart, $periodEnd));
            }

            $users = $usersSync->sync();
            $this->line('Usuarios consultados: '.$users['queried']);
            $this->line('Usuarios sincronizados: '.$users['saved']);
            $this->line('  Insertados/actualizados/sin cambios: '.($users['inserted'] ?? 0).'/'.($users['updated'] ?? 0).'/'.($users['unchanged'] ?? 0));

            $leads = $leadsSync->sync($periodStart, $periodEnd);
            $this->line('Leads consultados: '.$leads['queried']);
            $this->line('Leads guardados: '.$leads['saved']);
            $this->line('Leads sincronizados: '.$leads['saved']);
            $this->line('  Contrato saved: Leads activos validos procesados (no escrituras fisicas).');
            $this->line('  Leads activos insertados/actualizados/sin cambios: '.($leads['active_inserted'] ?? 0).'/'.($leads['active_updated'] ?? 0).'/'.($leads['active_unchanged'] ?? 0));
            $this->line('  Persistencia total insertados/actualizados/sin cambios (incluye eliminados/fusionados): '.($leads['persisted_inserted'] ?? $leads['inserted'] ?? 0).'/'.($leads['persisted_updated'] ?? $leads['updated'] ?? 0).'/'.($leads['persisted_unchanged'] ?? $leads['unchanged'] ?? 0));
            $this->line('Leads eliminados/fusionados marcados: '.($leads['deleted'] ?? 0));
            $this->line('  Eliminados/fusionados cambiados/sin cambios: '.($leads['deleted_merged_changed'] ?? 0).'/'.($leads['deleted_merged_unchanged'] ?? 0));
            $this->line('  Detectados por queryAll: '.($leads['deleted_query_all'] ?? 0));
            $this->line('  Ausentes en reconciliacion: '.($leads['deleted_missing'] ?? 0));
            $this->line('Corte sincronizacion Leads: '.($leads['synced_at'] ?? '-'));
            foreach ($leads['warnings'] ?? [] as $warning) {
                $this->warn($warning);
            }
            $this->warnIfEmpty('leads', $leads['queried'], $periodStart, $periodEnd);

            $tasks = $activitiesSync->syncTasks($periodStart, $periodEnd);
            $this->line('Tasks consultadas: '.$tasks['queried']);
            $this->line('Tasks guardadas: '.$tasks['saved']);
            $this->line('Tasks sincronizadas: '.$tasks['saved']);
            $this->line('  Insertadas/actualizadas/sin cambios: '.($tasks['inserted'] ?? 0).'/'.($tasks['updated'] ?? 0).'/'.($tasks['unchanged'] ?? 0));
            $this->warnIfEmpty('tasks', $tasks['queried'], $periodStart, $periodEnd);

            $events = $activitiesSync->syncEvents($periodStart, $periodEnd);
            $this->line('Events consultados: '.$events['queried']);
            $this->line('Events guardados: '.$events['saved']);
            $this->line('Events sincronizados: '.$events['saved']);
            $this->line('  Insertados/actualizados/sin cambios: '.($events['inserted'] ?? 0).'/'.($events['updated'] ?? 0).'/'.($events['unchanged'] ?? 0));
            $this->warnIfEmpty('events', $events['queried'], $periodStart, $periodEnd);

            $this->line('Activities totales guardadas: '.($tasks['saved'] + $events['saved']));

            $summaries = $summaryService->recalculateForPeriodWithStats($periodStart, $periodEnd);
            $this->line("Summaries generados: {$summaries['saved']}");
            $this->line("Summaries por lead generados: {$summaries['saved']}");
            $this->line("Summaries cambiados: {$summaries['summaries_changed']}");
            $this->line("Summaries sin cambios: {$summaries['summaries_unchanged']}");

            $this->info('Sincronizacion mensual comercial completada.');
            $this->invalidateDashboardCache();
            $syncRuns->complete($syncRun, CarbonImmutable::parse($leads['synced_at'] ?? now()), [
                'users' => $users,
                'leads' => $leads,
                'tasks' => $tasks,
                'events' => $events,
                'summaries' => $summaries['saved'],
                'summary_stats' => $summaries,
                'summaries_changed' => $summaries['summaries_changed'],
                'summaries_unchanged' => $summaries['summaries_unchanged'],
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $syncRuns->fail($syncRun, $exception);
            $this->error('Error sincronizando Salesforce.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function freshNewTables(): void
    {
        SalesforceActivity::query()->delete();
        SalesforceLeadActivitySummary::query()->delete();
        SalesforceLead::query()->delete();
        SalesforceUser::query()->delete();

        $this->warn('Tablas nuevas de Salesforce mensual vaciadas.');
    }

    private function warnIfEmpty(string $kind, int $queried, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): void
    {
        if ($queried > 0) {
            return;
        }

        $this->warn("Salesforce devolvio 0 {$kind} para el periodo {$this->soqlDateTime($periodStart)} - {$this->soqlDateTime($periodEnd)}");
    }

    private function printSoql(string $label, string $soql): void
    {
        $this->newLine();
        $this->line("SOQL {$label}:");
        $this->line($soql);
    }

    private function soqlDateTime(CarbonImmutable $date): string
    {
        return $date->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function invalidateDashboardCache(): void
    {
        Cache::forever('lead_dashboard_cache_version', ((int) Cache::get('lead_dashboard_cache_version', 1)) + 1);
        $this->line('Cache del dashboard Salesforce invalidada.');
    }
}
