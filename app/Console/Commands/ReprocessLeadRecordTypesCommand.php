<?php

namespace App\Console\Commands;

use App\Models\SalesforceLead;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReprocessLeadRecordTypesCommand extends Command
{
    protected $signature = 'reports:reprocess-lead-record-types
        {--dry-run : Muestra los cambios sin persistirlos}
        {--from= : Inicio inclusivo YYYY-MM-DD por Lead.CreatedDate}
        {--to= : Fin exclusivo YYYY-MM-DD por Lead.CreatedDate}
        {--chunk=500 : Tamaño de lote entre 50 y 1000}';

    protected $description = 'Reconstruye el tipo normalizado de Leads desde RecordType.Name usando la regla vigente.';

    public function handle(LeadRecordTypeNormalizer $normalizer): int
    {
        $chunk = min(1000, max(50, (int) $this->option('chunk')));
        $from = $this->parseDateOption('from');
        $to = $this->parseDateOption('to');

        if (($this->option('from') && $from === null) || ($this->option('to') && $to === null)) {
            $this->error('Las fechas deben usar el formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($from !== null && $to !== null && $from->greaterThanOrEqualTo($to)) {
            $this->error('--to debe ser posterior a --from.');

            return self::FAILURE;
        }

        $query = SalesforceLead::query()
            ->when($from, fn ($builder) => $builder->where('created_date', '>=', $from))
            ->when($to, fn ($builder) => $builder->where('created_date', '<', $to))
            ->orderBy('id');
        $examined = 0;
        $changed = 0;
        $leadToVenta = 0;
        $ayvensToVenta = 0;
        $otherChanges = 0;
        $dryRun = (bool) $this->option('dry-run');

        $query->chunkById($chunk, function ($rows) use ($normalizer, $dryRun, &$examined, &$changed, &$leadToVenta, &$ayvensToVenta, &$otherChanges): void {
            foreach ($rows as $lead) {
                $examined++;
                $target = $normalizer->normalize($lead->record_type_name);

                if ($lead->record_type_normalized === $target) {
                    continue;
                }

                $changed++;
                $current = (string) $lead->record_type_normalized;
                if ($current === LeadRecordTypeNormalizer::LEAD && $target === LeadRecordTypeNormalizer::VENTA) {
                    $leadToVenta++;
                } elseif ($current === LeadRecordTypeNormalizer::AYVENS && $target === LeadRecordTypeNormalizer::VENTA) {
                    $ayvensToVenta++;
                } else {
                    $otherChanges++;
                }

                if (! $dryRun) {
                    $lead->forceFill(['record_type_normalized' => $target])->save();
                }
            }
        });

        $this->info('Período: '.($from?->toDateString() ?? 'inicio histórico').' a '.($to?->toDateString() ?? 'fin histórico exclusivo'));
        $this->line("Registros examinados: {$examined}");
        $this->line("Registros que cambiarían: {$changed}");
        $this->line("Lead -> Venta: {$leadToVenta}");
        $this->line("Ayvens -> Venta: {$ayvensToVenta}");
        $this->line("Otros cambios: {$otherChanges}");
        $this->line('Tablas derivadas que requieren reconstrucción: campaign_salesforce_leads y atribuciones de Campañas.');
        $this->warn('No se reconstruyen Campañas automáticamente en este lote.');

        return self::SUCCESS;
    }

    private function parseDateOption(string $option): ?CarbonImmutable
    {
        $value = trim((string) $this->option($option));

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
