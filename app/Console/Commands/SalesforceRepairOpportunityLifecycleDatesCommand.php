<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceOpportunityLifecycleDateRepairService;
use Illuminate\Console\Command;
use Throwable;

class SalesforceRepairOpportunityLifecycleDatesCommand extends Command
{
    protected $signature = 'salesforce:repair-opportunity-lifecycle-dates
        {--dry-run : Simula la reparacion sin persistir cambios}
        {--apply : Persiste exclusivamente los metadatos temporales}
        {--reason= : Motivo obligatorio para --apply (entre 10 y 500 caracteres)}
        {--limit= : Maximo de Opportunities locales a procesar}
        {--after-id= : Cursor exclusivo basado en el ID local numerico}';

    protected $description = 'Repara CreatedDate y LastModifiedDate de Opportunities locales incompletas.';

    public function handle(SalesforceOpportunityLifecycleDateRepairService $repair): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun === $apply) {
            $this->error('Debe indicarse exactamente uno de --dry-run o --apply.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        $reasonLength = mb_strlen($reason);
        if ($apply && ($reasonLength < 10 || $reasonLength > 500)) {
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
            $stats = $repair->run($apply, $apply ? $reason : null, $limit, $afterId);
        } catch (Throwable $exception) {
            $message = str_starts_with($exception->getMessage(), 'Ya existe otra reparacion')
                ? $exception->getMessage()
                : 'No se pudo iniciar la reparacion de fechas de forma segura.';
            $this->error($message);

            return self::FAILURE;
        }

        $this->line('OPPORTUNITY_DATE_REPAIR_METRICS='.json_encode(
            $stats,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        if ($stats['failed']) {
            $this->error('La reparacion se detuvo; reanudar desde last_local_id_processed tras resolver la causa.');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Simulacion completada sin persistencia local.'
            : 'Reparacion local completada con auditoria de ejecucion.');

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
