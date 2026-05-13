<?php

namespace App\Console\Commands;

use App\Models\MonthlyCommercialReportSnapshot;
use App\Services\Reports\MonthlyCommercial\MonthlyCommercialPeriodService;
use App\Services\Reports\MonthlyCommercial\MonthlyCommercialReportBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RefreshMonthlyCommercialReportCommand extends Command
{
    protected $signature = 'reports:refresh-monthly-commercial
        {--days=30 : Numero de dias del periodo actual}
        {--store : Guarda el snapshot en base de datos}
        {--show-summary : Muestra un resumen por consola}';

    protected $description = 'Calcula el informe mensual comercial y opcionalmente guarda un snapshot.';

    public function handle(
        MonthlyCommercialReportBuilder $builder,
        MonthlyCommercialPeriodService $periodService,
    ): int {
        $days = max((int) $this->option('days'), 1);

        try {
            $payload = $builder->build($days);
            $periods = $periodService->periods($days, CarbonImmutable::parse($payload['periodos_estandar']['periodo_actual']['fin']));

            if ($this->option('store')) {
                MonthlyCommercialReportSnapshot::create([
                    'period_start' => $periods['current_start'],
                    'period_end' => $periods['current_end'],
                    'previous_period_start' => $periods['previous_start'],
                    'previous_period_end' => $periods['previous_end'],
                    'payload_json' => $payload,
                    'generated_at' => CarbonImmutable::now(),
                ]);

                $this->info('Snapshot del informe mensual guardado.');
            }

            if ($this->option('show-summary')) {
                $summary = $payload['resumen_global'];

                $this->line('Resumen informe mensual comercial');
                $this->line('Leads en analisis: '.$summary['leads_totales']);
                $this->line('Convertidos: '.$summary['leads_convertidos']);
                $this->line('Descartados: '.$summary['leads_descartados']);
                $this->line('Potenciales sin seguimiento >3 dias: '.$summary['potenciales_sin_seguimiento_mayor_3_dias']);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Error calculando el informe mensual comercial.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }
}
