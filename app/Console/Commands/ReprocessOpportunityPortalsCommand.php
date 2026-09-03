<?php

namespace App\Console\Commands;

use App\Services\Reports\ReservationsSales\OpportunityPortalReprocessService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ReprocessOpportunityPortalsCommand extends Command
{
    protected $signature = 'reports:reprocess-opportunity-portals
        {--from= : Fecha created_date inicial inclusiva (Y-m-d)}
        {--to= : Fecha created_date final exclusiva (Y-m-d)}
        {--dry-run : Simula la resolucion sin persistir cambios}
        {--apply : Persiste cambios locales con historico auditable}
        {--reason= : Motivo obligatorio para --apply (minimo 10 caracteres)}
        {--limit= : Maximo de Opportunities locales a procesar}
        {--after-id= : Cursor exclusivo basado en el ID local numerico}';

    protected $description = 'Reprocesa de forma segura y auditable la atribucion de portales de Opportunities locales.';

    public function handle(OpportunityPortalReprocessService $reprocess): int
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

        $afterId = $this->positiveIntegerOption('after-id');
        if ($this->option('after-id') !== null && $afterId === null) {
            $this->error('--after-id debe ser un ID local numerico positivo.');

            return self::FAILURE;
        }

        try {
            $stats = $reprocess->run(
                $from,
                $to,
                $apply,
                $apply ? $reason : null,
                $limit,
                $afterId,
            );
        } catch (Throwable $exception) {
            $message = str_starts_with($exception->getMessage(), 'Ya existe otro reproceso')
                ? $exception->getMessage()
                : 'No se pudo iniciar el reproceso de forma segura.';
            $this->error($message);

            return self::FAILURE;
        }

        $this->line('REPROCESS_METRICS='.json_encode(
            $stats,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        if ($stats['failed']) {
            $this->error('El reproceso se detuvo; reanudar desde last_local_id_processed tras resolver la causa.');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Simulacion completada sin persistencia local.'
            : 'Reproceso local completado con historico auditable.');

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
}
