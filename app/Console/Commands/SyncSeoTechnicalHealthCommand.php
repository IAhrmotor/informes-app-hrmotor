<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportSyncRunService;
use App\Services\SeoAnalytics\SeoTechnicalHealthSyncService;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SyncSeoTechnicalHealthCommand extends Command
{
    protected $signature = 'seo:sync-technical-health {--limit= : Limite manual de URLs (1-500)}';

    protected $description = 'Comprueba la salud tecnica de un conjunto acotado de URLs SEO.';

    public function handle(SeoTechnicalHealthSyncService $sync, ReportSyncRunService $runs): int
    {
        if (! $sync->configured()) {
            $this->warn('SKIPPED: sitio publico SEO pendiente de configurar.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit');
        if ($limit !== null && filter_var($limit, FILTER_VALIDATE_INT) === false) {
            $this->error('--limit debe ser un entero entre 1 y 500.');

            return self::FAILURE;
        }
        $limit = $limit === null ? null : (int) $limit;
        if ($limit !== null && ($limit < 1 || $limit > 500)) {
            $this->error('--limit debe estar entre 1 y 500.');

            return self::FAILURE;
        }

        try {
            $siteHost = $sync->siteHost();
        } catch (Throwable $exception) {
            $this->error(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

            return self::FAILURE;
        }

        $now = CarbonImmutable::now((string) config('seo_analytics.timezone', 'Europe/Madrid'));
        $run = $runs->start(SeoTechnicalHealthSyncService::DATASET, 'public_website', $now, $now, (string) config('seo_analytics.timezone'));
        $run->update(['stats' => ['site_host' => $siteHost]]);

        try {
            $result = $sync->sync($limit);
            $runs->complete($run, $result['checked_at'], $result['stats']);
            $this->info('Salud tecnica SEO comprobada: '.$result['stats']['checked_urls'].' URLs.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $runs->fail($run, $exception);
            $this->error('Error comprobando salud tecnica SEO.');
            $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

            return self::FAILURE;
        }
    }
}
