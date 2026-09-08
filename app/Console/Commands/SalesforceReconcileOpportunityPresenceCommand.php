<?php

namespace App\Console\Commands;

use App\Services\Reports\Stock\StockSaleValidityService;
use App\Services\Salesforce\SalesforceOpportunityPresenceReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class SalesforceReconcileOpportunityPresenceCommand extends Command
{
    protected $signature = 'salesforce:reconcile-opportunity-presence
        {--dry-run : Simula la reconciliacion sin persistir cambios}
        {--apply : Persiste lifecycle y reconcilia la validez Stock si confirma borrados}
        {--reason= : Motivo obligatorio para --apply (entre 10 y 500 caracteres)}
        {--limit= : Maximo de Opportunities locales a procesar}
        {--after-id= : Cursor exclusivo basado en el ID local numerico}';

    protected $description = 'Concilia por lotes la presencia y borrado de Opportunities locales contra Salesforce.';

    public function handle(
        SalesforceOpportunityPresenceReconciliationService $service,
        StockSaleValidityService $stockSaleValidity,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            $this->error('Debe indicarse exactamente uno de --dry-run o --apply.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($apply && (mb_strlen($reason) < 10 || mb_strlen($reason) > 500)) {
            $this->error('--apply requiere --reason con entre 10 y 500 caracteres no whitespace.');

            return self::FAILURE;
        }

        $limit = $this->positiveIntegerOption('limit');
        if ($this->option('limit') !== null && $limit === null) {
            $this->error('--limit debe ser un entero positivo.');

            return self::FAILURE;
        }

        $afterId = $this->positiveIntegerOption('after-id');
        if ($this->option('after-id') !== null && $afterId === null) {
            $this->error('--after-id debe ser un ID local numerico positivo.');

            return self::FAILURE;
        }

        try {
            $stats = $service->run($apply, $apply ? $reason : null, $limit, $afterId);
        } catch (Throwable $exception) {
            $message = str_starts_with($exception->getMessage(), 'Ya existe otra reconciliacion')
                ? $exception->getMessage()
                : 'No se pudo completar la reconciliacion de presencia de forma segura.';
            $this->error($message);

            return self::FAILURE;
        }

        $this->line('OPPORTUNITY_PRESENCE_METRICS='.json_encode(
            $stats,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        if ($apply && $stats['rows_marked_deleted'] > 0) {
            try {
                $stockValidity = $stockSaleValidity->reconcile();
            } catch (Throwable $exception) {
                report($exception);
                $this->error('El lifecycle local se actualizo, pero fallo la reconciliacion de validez Stock.');
                $this->line('Reintente exclusivamente con: php artisan stock:reconcile-sale-validity');

                return self::FAILURE;
            }

            $this->line('STOCK_SALE_VALIDITY_METRICS='.json_encode(
                $stockValidity,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }

        if ($stats['failed']) {
            $this->error('La reconciliacion se detuvo; reanudar desde last_local_id_processed tras resolver la causa.');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Simulacion completada sin persistencia local.'
            : 'Reconciliacion local completada con auditoria de ejecucion.');

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $validated === false ? null : $validated;
    }
}
