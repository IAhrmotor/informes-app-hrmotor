<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportSyncRunService;
use App\Services\SeoAnalytics\Ga4OrganicConversionSyncService;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SyncSeoGa4OrganicCommand extends Command
{
    protected $signature = 'seo:sync-ga4-organic {--days=120 : Dias operativos que se sincronizan (1-480)}';

    protected $description = 'Sincroniza Conversiones web organicas de Google Analytics 4.';

    public function handle(Ga4OrganicConversionSyncService $sync, ReportSyncRunService $runs): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > (int) config('seo_analytics.max_history_sync_days', 480)) {
            $this->error('--days debe ser un entero entre 1 y 480.');

            return self::FAILURE;
        }

        if (! $sync->configured()) {
            $this->warn('SKIPPED: Google Analytics 4 pendiente de configurar.');

            return self::SUCCESS;
        }

        $estimatedEnd = CarbonImmutable::now(config('seo_analytics.timezone'))->startOfDay();
        $run = $runs->start(
            Ga4OrganicConversionSyncService::DATASET,
            'google_analytics',
            $estimatedEnd->subDays($days),
            $estimatedEnd,
            (string) config('seo_analytics.timezone'),
        );
        $run->update(['stats' => ['property_id' => $sync->configuredPropertyId()]]);

        try {
            $result = $sync->sync($days);
            $run->update([
                'period_start_at' => $result['period_start'],
                'period_end_at' => $result['period_end'],
                'timezone' => $result['stats']['timezone'],
            ]);
            $runs->complete($run, $result['cutoff'], $result['stats']);
            $this->info('Google Analytics 4 sincronizado hasta '.$result['cutoff']->toDateString().'.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $runs->fail($run, $exception);
            $this->error('Error sincronizando Google Analytics 4.');
            $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

            return self::FAILURE;
        }
    }
}
