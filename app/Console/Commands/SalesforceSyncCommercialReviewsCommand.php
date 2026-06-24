<?php

namespace App\Console\Commands;

use App\Models\SalesforceReview;
use App\Services\Reports\CommercialCommissions\Sync\SalesforceReviewSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncCommercialReviewsCommand extends Command
{
    protected $signature = 'salesforce:sync-commercial-reviews
        {--days=60 : Numero de dias hacia atras que se sincronizan}
        {--months= : Meses hacia atras que se sincronizan; tiene prioridad sobre --days}
        {--from= : Fecha inicial explicita en formato Y-m-d}
        {--to= : Fecha final exclusiva explicita en formato Y-m-d}
        {--all : Sincroniza el historico completo de resenas desde 2020-01-01}
        {--fresh : Borra solo la tabla de resenas Salesforce antes de sincronizar}
        {--debug-soql : Imprime la query SOQL ejecutada}';

    protected $description = 'Sincroniza resenas de Salesforce para el informe de Comisiones Comerciales.';

    public function handle(SalesforceReviewSyncService $sync): int
    {
        $periodEnd = $this->periodEnd();
        $periodStart = $this->periodStart($periodEnd);

        if (! $this->option('all') && $periodEnd->lessThanOrEqualTo($periodStart)) {
            $this->error('El rango indicado no es valido: --to debe ser posterior a --from.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            SalesforceReview::query()->delete();
            $this->warn('Tabla salesforce_reviews vaciada.');
        }

        try {
            $this->info('Sincronizando resenas Salesforce.');
            $this->line('Periodo inicio: '.$periodStart->utc()->format('Y-m-d\TH:i:s\Z'));
            $this->line('Periodo fin exclusivo: '.$periodEnd->utc()->format('Y-m-d\TH:i:s\Z'));

            if ($this->option('debug-soql')) {
                $this->newLine();
                $this->line('SOQL Resenas:');
                $this->line($sync->soql(
                    $this->option('all') ? CarbonImmutable::parse('2020-01-01')->startOfDay() : $periodStart,
                    $periodEnd
                ));
                $this->newLine();
            }

            $result = $this->option('all')
                ? $sync->syncAllHistory($periodEnd)
                : $sync->sync($periodStart, $periodEnd);

            $this->line('Resenas consultadas: '.$result['queried']);
            $this->line('Resenas guardadas: '.$result['saved']);

            if ($result['queried'] === 0) {
                $this->warn('Salesforce devolvio 0 resenas para el periodo indicado.');
            }

            $this->info('Sincronizacion de resenas completada.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Error sincronizando resenas.');
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
