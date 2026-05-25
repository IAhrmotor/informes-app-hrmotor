<?php

namespace App\Console\Commands;

use App\Models\SalesforceCall;
use App\Services\Reports\Calls\SalesforceCallSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncCallsCommand extends Command
{
    protected $signature = 'salesforce:sync-calls
        {--days=60 : Numero de dias hacia atras que se sincronizan}
        {--fresh : Borra solo la tabla de llamadas Salesforce antes de sincronizar}
        {--debug-soql : Imprime la query SOQL ejecutada}';

    protected $description = 'Sincroniza Tasks de tipo llamada de Salesforce para el dashboard Llamadas.';

    public function handle(SalesforceCallSyncService $syncService): int
    {
        $days = max((int) $this->option('days'), 1);
        $periodEnd = CarbonImmutable::now();
        $periodStart = $periodEnd->subDays($days);

        if ($this->option('fresh')) {
            SalesforceCall::query()->delete();
            $this->warn('Tabla salesforce_calls vaciada.');
        }

        try {
            $this->info('Sincronizando llamadas Salesforce.');
            $this->line('Periodo inicio: '.$periodStart->utc()->format('Y-m-d\TH:i:s\Z'));
            $this->line('Periodo fin: '.$periodEnd->utc()->format('Y-m-d\TH:i:s\Z'));

            if ($this->option('debug-soql')) {
                $this->newLine();
                $this->line($syncService->soql($periodStart, $periodEnd));
                $this->newLine();
            }

            $result = $syncService->sync($periodStart, $periodEnd);
            $stats = $result['stats'];

            $this->line('Llamadas consultadas: '.$result['queried']);
            $this->line('Llamadas guardadas: '.$result['saved']);
            $this->line('Atendidas: '.$stats['answered']);
            $this->line('No atendidas/perdidas: '.$stats['not_answered']);
            $this->line('Entrantes: '.$stats['inbound']);
            $this->line('Salientes: '.$stats['outbound']);
            $this->line('Comercial directo: '.$stats['commercial_direct']);
            $this->line('Centralita: '.$stats['switchboard']);
            $this->line('Portal/procedencia: '.$stats['portal']);

            foreach ($stats['teams'] as $team => $total) {
                $this->line('Equipo '.$team.': '.$total);
            }

            $this->info('Sincronizacion de llamadas completada.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Error sincronizando llamadas.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }
}
