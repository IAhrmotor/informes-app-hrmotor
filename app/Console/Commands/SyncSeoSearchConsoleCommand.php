<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportSyncRunService;
use App\Services\SeoAnalytics\SearchConsoleSyncService;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SyncSeoSearchConsoleCommand extends Command
{
    protected $signature = 'seo:sync-search-console {--days=120 : Dias cerrados que se sincronizan (1-480)}';

    protected $description = 'Sincroniza agregados finales y rankings de Google Search Console.';

    public function handle(SearchConsoleSyncService $sync, ReportSyncRunService $runs): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > (int) config('seo_analytics.max_history_sync_days', 480)) {
            $this->error('--days debe ser un entero entre 1 y 480.');

            return self::FAILURE;
        }

        if (! $sync->configured()) {
            $this->warn('SKIPPED: Search Console pendiente de configurar.');

            return self::SUCCESS;
        }

        $estimatedEnd = CarbonImmutable::now(config('seo_analytics.search_console_timezone'))->startOfDay();
        $run = $runs->start(SearchConsoleSyncService::DATASET, 'google_search_console', $estimatedEnd->subDays($days), $estimatedEnd, (string) config('seo_analytics.search_console_timezone'));
        $run->update(['stats' => ['property' => $sync->configuredProperty()]]);

        try {
            $result = $sync->sync($days);
            $run->update([
                'period_start_at' => $result['period_start'],
                'period_end_at' => $result['period_end'],
            ]);
            $runs->complete($run, $result['cutoff'], $result['stats']);
            $this->info('Search Console sincronizado hasta '.$result['cutoff']->toDateString().'.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $runs->fail($run, $exception);
            $this->error('Error sincronizando Search Console.');
            $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

            return self::FAILURE;
        }
    }
}
