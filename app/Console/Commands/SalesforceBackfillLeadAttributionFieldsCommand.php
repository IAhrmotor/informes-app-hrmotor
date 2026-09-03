<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceLeadAttributionBackfillService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SalesforceBackfillLeadAttributionFieldsCommand extends Command
{
    protected $signature = 'salesforce:backfill-lead-attribution-fields
        {--from= : Fecha local created_date inicial inclusiva (Y-m-d)}
        {--to= : Fecha local created_date final exclusiva (Y-m-d)}
        {--dry-run : Simula y muestra cambios sin persistir nada}
        {--apply : Persiste cambios locales y su historico auditable}
        {--reason= : Motivo operativo obligatorio para --apply (minimo 10 caracteres)}
        {--limit= : Maximo de Salesforce Lead IDs locales unicos a procesar}
        {--after-salesforce-id= : Cursor exclusivo para reanudar por Salesforce Lead ID}
        {--debug-soql : Imprime cada consulta SOQL de solo lectura}';

    protected $description = 'Materializa de forma segura los campos historicos de atribucion de Leads ya existentes localmente.';

    public function handle(SalesforceLeadAttributionBackfillService $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun === $apply) {
            $this->error('Debe indicarse exactamente uno de --dry-run o --apply.');

            return self::FAILURE;
        }

        $from = $this->dateOption('from');
        $to = $this->dateOption('to');

        if ($from === null || $to === null || $to->lessThanOrEqualTo($from)) {
            $this->error('--from y --to son obligatorios, deben usar Y-m-d y definir un rango valido [from, to).');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($apply && mb_strlen($reason) < 10) {
            $this->error('--apply requiere --reason con al menos 10 caracteres no whitespace.');

            return self::FAILURE;
        }

        $limit = $this->positiveIntegerOption('limit');
        if ($this->option('limit') !== null && $limit === null) {
            $this->error('--limit debe ser un entero positivo.');

            return self::FAILURE;
        }

        $afterSalesforceId = $this->nullableStringOption('after-salesforce-id');
        if ($afterSalesforceId !== null && preg_match('/^00Q[A-Za-z0-9]{12}(?:[A-Za-z0-9]{3})?$/', $afterSalesforceId) !== 1) {
            $this->error('--after-salesforce-id debe ser un Salesforce Lead ID valido de 15 o 18 caracteres.');

            return self::FAILURE;
        }

        try {
            $stats = $backfill->run(
                $from,
                $to,
                $apply,
                $apply ? $reason : null,
                $limit,
                $afterSalesforceId,
                $this->option('debug-soql')
                    ? function (string $soql): void {
                        $this->newLine();
                        $this->line('SOQL Lead attribution backfill:');
                        $this->line($soql);
                    }
                : null,
            );
        } catch (Throwable $exception) {
            $this->error('No se pudo preparar el backfill: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('BACKFILL_METRICS='.json_encode(
            $stats,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        if ($stats['failed']) {
            $this->error('El backfill se detuvo. Reanudar desde last_salesforce_id_processed tras resolver la causa.');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Simulacion completada: no se ha persistido ningun cambio.'
            : 'Backfill local completado con historico auditable.');

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = trim((string) $this->option($name));

        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
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

    private function nullableStringOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }
}
