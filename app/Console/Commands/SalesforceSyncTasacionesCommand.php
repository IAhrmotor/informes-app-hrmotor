<?php

namespace App\Console\Commands;

use App\Models\SalesforceTasacion;
use App\Services\Reports\CallCenterCommissions\Sync\SalesforceTasacionSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncTasacionesCommand extends Command
{
    protected $signature = 'salesforce:sync-tasaciones
        {--days=60 : Numero de dias hacia atras que se sincronizan}
        {--months= : Meses hacia atras que se sincronizan; tiene prioridad sobre --days}
        {--from= : Fecha inicial explicita en formato Y-m-d}
        {--to= : Fecha final exclusiva explicita en formato Y-m-d}
        {--all : Sincroniza el historico completo de tasaciones desde 2020-01-01}
        {--fresh : Borra solo la tabla local de tasaciones antes de sincronizar}
        {--debug-soql : Imprime la query SOQL ejecutada}';

    protected $description = 'Sincroniza Tasacion__c de Salesforce para Negociaciones German del informe de Call Center.';

    public function handle(SalesforceTasacionSyncService $sync): int
    {
        $periodEnd = $this->periodEnd();
        $periodStart = $this->periodStart($periodEnd);

        if (! $this->option('all') && $periodEnd->lessThanOrEqualTo($periodStart)) {
            $this->error('El rango indicado no es valido: --to debe ser posterior a --from.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            SalesforceTasacion::query()->delete();
            $this->warn('Tabla salesforce_tasaciones vaciada.');
        }

        try {
            $this->info('Sincronizando tasaciones Salesforce para Call Center.');
            $this->line('Periodo inicio: '.$periodStart->utc()->format('Y-m-d\TH:i:s\Z'));
            $this->line('Periodo fin exclusivo: '.$periodEnd->utc()->format('Y-m-d\TH:i:s\Z'));

            if ($this->option('debug-soql')) {
                $this->newLine();
                $this->line('SOQL Tasaciones:');
                $this->line($sync->soql(
                    $this->option('all') ? CarbonImmutable::parse('2020-01-01')->startOfDay() : $periodStart,
                    $periodEnd
                ));
                $this->newLine();
            }

            $result = $this->option('all')
                ? $sync->syncAllHistory($periodEnd)
                : $sync->sync($periodStart, $periodEnd);

            $this->line('Tasaciones consultadas: '.$result['queried']);
            $this->line('Tasaciones guardadas: '.$result['saved']);
            $this->line('Perfiles SOQL usados: '.implode(', ', $result['profiles'] ?: ['ninguno']));

            if ($result['queried'] === 0) {
                $this->warn('Salesforce devolvio 0 tasaciones para el periodo indicado.');
            }

            $this->info('Sincronizacion de tasaciones completada.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Error sincronizando tasaciones.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
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
