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
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncMonthlyCommercialCommand extends Command
{
    protected $signature = 'salesforce:sync-monthly-commercial
        {--days=60 : Numero de dias hacia atras que se sincronizan}
        {--fresh : Borra solo las tablas Salesforce mensuales nuevas antes de sincronizar}
        {--debug-soql : Imprime las queries SOQL ejecutadas}';

    protected $description = 'Sincroniza usuarios, leads, Task, Event y summaries para el informe mensual comercial.';

    public function handle(
        SalesforceMonthlyUsersSyncService $usersSync,
        SalesforceMonthlyLeadsSyncService $leadsSync,
        SalesforceMonthlyActivitiesSyncService $activitiesSync,
        SalesforceLeadActivitySummaryService $summaryService,
    ): int {
        $days = max((int) $this->option('days'), 1);
        $periodEnd = CarbonImmutable::now();
        $periodStart = $periodEnd->subDays($days);

        if ($this->option('fresh')) {
            $this->freshNewTables();
        }

        try {
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

            $leads = $leadsSync->sync($periodStart, $periodEnd);
            $this->line('Leads consultados: '.$leads['queried']);
            $this->line('Leads guardados: '.$leads['saved']);
            $this->line('Leads sincronizados: '.$leads['saved']);
            $this->warnIfEmpty('leads', $leads['queried'], $periodStart, $periodEnd);

            $tasks = $activitiesSync->syncTasks($periodStart, $periodEnd);
            $this->line('Tasks consultadas: '.$tasks['queried']);
            $this->line('Tasks guardadas: '.$tasks['saved']);
            $this->line('Tasks sincronizadas: '.$tasks['saved']);
            $this->warnIfEmpty('tasks', $tasks['queried'], $periodStart, $periodEnd);

            $events = $activitiesSync->syncEvents($periodStart, $periodEnd);
            $this->line('Events consultados: '.$events['queried']);
            $this->line('Events guardados: '.$events['saved']);
            $this->line('Events sincronizados: '.$events['saved']);
            $this->warnIfEmpty('events', $events['queried'], $periodStart, $periodEnd);

            $this->line('Activities totales guardadas: '.($tasks['saved'] + $events['saved']));

            $summaries = $summaryService->recalculateForPeriod($periodStart, $periodEnd);
            $this->line("Summaries generados: {$summaries}");
            $this->line("Summaries por lead generados: {$summaries}");

            $this->info('Sincronizacion mensual comercial completada.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
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
}
