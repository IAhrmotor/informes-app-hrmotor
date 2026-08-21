<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportSyncRunService;
use App\Services\SeoAnalytics\SeoAnalyticalEvaluationService;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class EvaluateSeoAnalyticalSnapshotsCommand extends Command
{
    protected $signature = 'seo:evaluate-analytical-snapshots {--days=1 : Snapshots por metrica que se evaluan (1-90)}';

    protected $description = 'Evalua snapshots analiticos SEO con el rule set activo, sin llamadas externas.';

    public function handle(SeoAnalyticalEvaluationService $evaluations, ReportSyncRunService $runs): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 90) {
            $this->error('--days debe ser un entero entre 1 y 90.');

            return self::FAILURE;
        }

        $timezone = (string) config('seo_analytics.timezone', 'Europe/Madrid');
        $now = CarbonImmutable::now($timezone);
        $run = $runs->start(
            SeoAnalyticalEvaluationService::DATASET,
            SeoAnalyticalEvaluationService::SOURCE,
            $now->startOfDay()->subDays($days - 1),
            $now->startOfDay(),
            $timezone,
        );

        try {
            $stats = $evaluations->evaluate($days);
            $cutoff = filled($stats['max_data_date'])
                ? CarbonImmutable::parse((string) $stats['max_data_date'], $timezone)->startOfDay()
                : $now->startOfDay();
            $runs->complete($run, $cutoff, $stats);
            $this->info("Snapshots evaluados: {$stats['snapshots_evaluated']} con {$stats['rule_version']}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $runs->fail($run, $exception);
            $this->error('Error evaluando snapshots analiticos SEO.');
            $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

            return self::FAILURE;
        }
    }
}
