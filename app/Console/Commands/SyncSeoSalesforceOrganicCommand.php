<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportSyncRunService;
use App\Services\SeoAnalytics\SalesforceOrganicLeadSyncService;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SyncSeoSalesforceOrganicCommand extends Command
{
    protected $signature = 'seo:sync-salesforce-organic {--days=120 : Dias cerrados que se sincronizan (1-480)}';

    protected $description = 'Sincroniza la proyeccion diaria de Leads organicos Salesforce para SEO.';

    public function handle(SalesforceOrganicLeadSyncService $sync, ReportSyncRunService $runs): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > (int) config('seo_analytics.max_history_sync_days', 480)) {
            $this->error('--days debe ser un entero entre 1 y 480.');

            return self::FAILURE;
        }

        if (! $sync->configured()) {
            $this->warn('SKIPPED: Salesforce pendiente de configurar.');

            return self::SUCCESS;
        }

        $estimatedEnd = CarbonImmutable::now(config('seo_analytics.timezone'))->startOfDay();
        $run = $runs->start(SalesforceOrganicLeadSyncService::DATASET, 'salesforce', $estimatedEnd->subDays($days), $estimatedEnd, (string) config('seo_analytics.timezone'));

        try {
            $result = $sync->sync($days);
            $run->update([
                'period_start_at' => $result['period_start'],
                'period_end_at' => $result['period_end'],
            ]);
            $runs->complete($run, $result['cutoff'], $result['stats']);
            $this->info('Leads organicos Salesforce sincronizados hasta '.$result['cutoff']->toDateString().'.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $runs->fail($run, $exception);
            $this->error('Error sincronizando Leads organicos Salesforce.');
            $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

            return self::FAILURE;
        }
    }
}
