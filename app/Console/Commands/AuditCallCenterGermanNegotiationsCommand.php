<?php

namespace App\Console\Commands;

use App\Services\Reports\CallCenterCommissions\CallCenterGermanNegotiationAuditService;
use Illuminate\Console\Command;

class AuditCallCenterGermanNegotiationsCommand extends Command
{
    protected $signature = 'reports:audit-call-center-german
        {--month= : Mes cerrado en formato YYYY-MM}
        {--from= : Fecha inicio del rango contrato en formato Y-m-d}
        {--to= : Fecha fin del rango contrato en formato Y-m-d}
        {--examples=5 : Numero de ejemplos por motivo}';

    protected $description = 'Audita las tasaciones de Negociaciones German para ver cuantas entran, cuantas quedan fuera y por que motivo.';

    public function handle(CallCenterGermanNegotiationAuditService $auditService): int
    {
        $audit = $auditService->audit(
            is_string($this->option('month')) ? $this->option('month') : null,
            is_string($this->option('from')) ? $this->option('from') : null,
            is_string($this->option('to')) ? $this->option('to') : null,
            max((int) $this->option('examples'), 0)
        );

        $this->info('Auditoria Negociaciones German');
        $this->line('Mes seleccionado: '.$audit['month']);
        $this->line('Rango contrato: '.$audit['contract_from'].' -> '.$audit['contract_to']);
        $this->line('Tasaciones sincronizadas: '.$audit['tasaciones_total']);
        $this->line('Tasaciones con seguimiento German: '.$audit['german_total']);
        $this->line('Entran en comision: '.$audit['included_total']);
        $this->line('Quedan fuera: '.$audit['excluded_total']);
        $this->newLine();

        $this->table(
            ['motivo', 'total'],
            collect($audit['reasons'])
                ->filter(fn (array $row, string $key): bool => $key !== 'not_german')
                ->map(fn (array $row): array => [$row['label'], $row['total']])
                ->values()
                ->all()
        );

        foreach ($audit['reasons'] as $reasonKey => $row) {
            if ($reasonKey === 'not_german' || ($row['total'] ?? 0) === 0) {
                continue;
            }

            $this->newLine();
            $this->line($row['label'].': '.$row['total']);

            if (($row['examples'] ?? []) === []) {
                continue;
            }

            $this->table(
                ['tasacion_id', 'tasacion_name', 'opportunity_id', 'opportunity_name', 'tracking', 'negociacion_1', 'fecha_firma', 'cv_firmado'],
                collect($row['examples'])
                    ->map(fn (array $example): array => [
                        $example['tasacion_id'] ?? '',
                        $example['tasacion_name'] ?? '',
                        $example['opportunity_id'] ?? '',
                        $example['opportunity_name'] ?? '',
                        $example['tracking_name'] ?? '',
                        $example['negotiation_1'] ?? '',
                        $example['contract_signed_date'] ?? '',
                        $example['cv_signed'] ?? '',
                    ])
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
