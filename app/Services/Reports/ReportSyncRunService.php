<?php

namespace App\Services\Reports;

use App\Models\ReportSyncRun;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonInterface;
use Throwable;

class ReportSyncRunService
{
    public function start(
        string $dataset,
        string $source,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        string $timezone = 'Europe/Madrid',
    ): ReportSyncRun {
        return ReportSyncRun::query()->create([
            'dataset' => $dataset,
            'source' => $source,
            'status' => 'running',
            'period_start_at' => $periodStart,
            'period_end_at' => $periodEnd,
            'started_at' => now(),
            'timezone' => $timezone,
        ]);
    }

    public function complete(ReportSyncRun $run, CarbonInterface $cutoff, array $stats = []): void
    {
        $run->update([
            'status' => 'completed',
            'source_cutoff_at' => $cutoff,
            'completed_at' => now(),
            'stats' => $stats,
            'error_message' => null,
        ]);
    }

    public function fail(ReportSyncRun $run, Throwable|string $error): void
    {
        $run->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => IntegrationErrorSanitizer::sanitizeMessage(
                $error instanceof Throwable ? $error->getMessage() : $error,
                2000,
            ),
        ]);
    }
}
