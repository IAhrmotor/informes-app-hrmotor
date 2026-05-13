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
        {--fresh : Borra solo las tablas Salesforce mensuales nuevas antes de sincronizar}';

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
            $this->info("Sincronizando Salesforce desde {$periodStart->toDateTimeString()} hasta {$periodEnd->toDateTimeString()}.");

            $users = $usersSync->sync();
            $this->line("Usuarios sincronizados: {$users}");

            $leads = $leadsSync->sync($periodStart, $periodEnd);
            $this->line("Leads sincronizados: {$leads}");

            $tasks = $activitiesSync->syncTasks($periodStart, $periodEnd);
            $this->line("Tasks sincronizadas: {$tasks}");

            $events = $activitiesSync->syncEvents($periodStart, $periodEnd);
            $this->line("Events sincronizados: {$events}");

            $summaries = $summaryService->recalculateForPeriod($periodStart, $periodEnd);
            $this->line("Summaries recalculados: {$summaries}");

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
}
