<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportSyncRunService;
use App\Services\SeoAnalytics\SeoExecutiveDailyEmailService;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SendSeoExecutiveDailyEmailCommand extends Command
{
    protected $signature = 'seo:send-executive-daily-email';

    protected $description = 'Envía el resumen ejecutivo diario de SEO y Analytics';

    public function handle(
        SeoExecutiveDailyEmailService $email,
        ReportSyncRunService $runs,
    ): int {
        $timezone = (string) config('seo_analytics.timezone', 'Europe/Madrid');
        $date = CarbonImmutable::now($timezone)->startOfDay();
        $run = $runs->start(
            SeoExecutiveDailyEmailService::DATASET,
            SeoExecutiveDailyEmailService::SOURCE,
            $date,
            $date->endOfDay(),
            $timezone,
        );

        try {
            $stats = $email->send($date);
            if ($stats['failed_count'] > 0 || $stats['in_progress_count'] > 0) {
                $run->update(['stats' => $stats]);
                throw new \RuntimeException('El resumen ejecutivo SEO no se entregó a todos los destinatarios.');
            }

            $runs->complete($run, $date->endOfDay(), $stats);
            $this->info(sprintf(
                'Resumen SEO procesado: %d enviados, %d ya enviados.',
                $stats['sent_count'],
                $stats['already_sent_count'],
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $runs->fail($run, $exception);
            $this->error(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

            return self::FAILURE;
        }
    }
}
