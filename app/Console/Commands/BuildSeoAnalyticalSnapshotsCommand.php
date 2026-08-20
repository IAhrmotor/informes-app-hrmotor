<?php

namespace App\Console\Commands;

use App\Services\Analytics\SameWeekdayComparisonEngine;
use App\Services\Reports\ReportSyncRunService;
use App\Services\SeoAnalytics\SeoAnalyticalSnapshotService;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class BuildSeoAnalyticalSnapshotsCommand extends Command
{
    protected $signature = 'seo:build-analytical-snapshots {--days= : Fechas objetivo que se reconstruyen (1-90; default configurado)}';

    protected $description = 'Construye snapshots comparativos diarios SEO desde datos locales persistidos.';

    public function handle(SeoAnalyticalSnapshotService $snapshots, ReportSyncRunService $runs): int
    {
        $requestedDays = $this->option('days');
        $requestedDays = $requestedDays === null || $requestedDays === ''
            ? config('seo_analytics.analytical_comparison.snapshot_refresh_days', 30)
            : $requestedDays;
        $days = filter_var($requestedDays, FILTER_VALIDATE_INT);
        $maximum = min(90, (int) config('seo_analytics.analytical_comparison.max_snapshot_build_days', 90));
        if ($days === false || $days < 1 || $days > $maximum) {
            $this->error("--days debe ser un entero entre 1 y {$maximum}.");

            return self::FAILURE;
        }

        $now = CarbonImmutable::now((string) config('seo_analytics.timezone'));
        $run = $runs->start(
            SeoAnalyticalSnapshotService::DATASET,
            SeoAnalyticalSnapshotService::SOURCE,
            $now->startOfDay()->subDays($days - 1),
            $now->startOfDay(),
            (string) config('seo_analytics.timezone'),
        );

        try {
            $result = $snapshots->build($days);
            $stats = [
                'engine_version' => SameWeekdayComparisonEngine::VERSION,
                'requested_days' => $days,
                'snapshots_upserted' => $result['rows'],
                'evaluable_snapshots' => $result['evaluable'],
                'not_evaluable_snapshots' => $result['not_evaluable'],
                'metric_count' => $result['metric_count'],
                'search_console_cutoff' => $result['cutoffs']['search_console'],
                'salesforce_cutoff' => $result['cutoffs']['salesforce'],
                'ga4_cutoff' => $result['cutoffs']['ga4'],
            ];
            $runs->complete($run, $result['max_cutoff'] ?? $now->startOfDay(), $stats);

            if ($result['rows'] === 0) {
                $this->info('Sin fuentes completadas; no se han generado snapshots analíticos.');
            } else {
                $this->info("Snapshots analíticos construidos: {$result['rows']}.");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $runs->fail($run, $exception);
            $this->error('Error construyendo snapshots analíticos SEO.');
            $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

            return self::FAILURE;
        }
    }
}
