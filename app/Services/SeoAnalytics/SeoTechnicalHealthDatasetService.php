<?php

namespace App\Services\SeoAnalytics;

use App\Models\ReportSyncRun;
use App\Models\SeoTechnicalUrl;
use Illuminate\Support\Collection;
use Throwable;

final class SeoTechnicalHealthDatasetService
{
    public function __construct(private readonly SeoTechnicalUrlGuard $guard) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        if (! $this->guard->configured()) {
            return $this->empty('Pendiente de configurar', 'Configure el sitio publico SEO antes de ejecutar comprobaciones.');
        }
        try {
            $host = strtolower((string) parse_url($this->guard->siteOrigin(), PHP_URL_HOST));
        } catch (Throwable) {
            return $this->empty('Configuracion invalida', 'La configuracion del sitio publico no es valida.');
        }

        $runs = ReportSyncRun::query()
            ->where('dataset', SeoTechnicalHealthSyncService::DATASET)
            ->latest('started_at')
            ->limit(50)
            ->get()
            ->filter(fn (ReportSyncRun $run): bool => data_get($run->stats, 'site_host') === $host)
            ->values();
        $latest = $runs->first();
        $completed = $runs->first(fn (ReportSyncRun $run): bool => $run->status === 'completed' && $run->source_cutoff_at !== null);
        $stats = $completed?->stats ?? [];
        $checkDate = data_get($stats, 'check_date');
        $rows = collect();
        if (is_string($checkDate)) {
            $rows = SeoTechnicalUrl::query()
                ->join('seo_technical_url_checks as checks', function ($join): void {
                    $join->on('checks.seo_technical_url_id', '=', 'seo_technical_urls.id');
                })
                ->where('seo_technical_urls.is_active', true)
                ->whereDate('checks.check_date', $checkDate)
                ->select(['seo_technical_urls.*', 'checks.final_url', 'checks.http_status', 'checks.redirect_count',
                    'checks.response_time_ms', 'checks.has_noindex', 'checks.canonical_url', 'checks.canonical_count',
                    'checks.canonical_matches_final', 'checks.body_truncated', 'checks.error_code', 'checks.error_message', 'checks.checked_at'])
                ->orderByRaw('CASE
                    WHEN checks.error_code IS NOT NULL THEN 1
                    WHEN checks.http_status >= 500 THEN 2
                    WHEN checks.http_status >= 400 THEN 3
                    WHEN checks.has_noindex = 1 THEN 4
                    WHEN checks.canonical_count > 1 OR checks.canonical_matches_final = 0 THEN 5
                    WHEN seo_technical_urls.in_sitemap = 0 THEN 6
                    WHEN checks.redirect_count > 0 THEN 7
                    ELSE 8 END')
                ->orderBy('seo_technical_urls.url')
                ->limit((int) config('seo_analytics.technical_health.visible_url_limit', 100))
                ->get();
        }

        $source = match ($latest?->status) {
            'failed' => ['badge' => 'Error ultimo sync', 'detail' => $completed ? 'Se muestran datos anteriores; la ultima comprobacion fallo.' : 'La ultima comprobacion fallo.'],
            'running' => ['badge' => 'Sincronizando', 'detail' => $completed ? 'Se muestran datos anteriores mientras se ejecuta una comprobacion.' : 'Comprobacion en curso.'],
            default => $completed
                ? ['badge' => 'Comprobada', 'detail' => 'Ultima comprobacion: '.$completed->source_cutoff_at->format('Y-m-d H:i:s')]
                : ['badge' => 'Sin comprobaciones', 'detail' => 'Configuracion detectada; todavia no existen comprobaciones.'],
        };

        return [
            'available' => $completed !== null,
            'source' => ['key' => 'technical-health', 'title' => 'Salud tecnica SEO'] + $source,
            'stats' => $stats,
            'rows' => $rows,
            'visible_count' => $rows->count(),
            'total_count' => (int) data_get($stats, 'checked_urls', 0),
        ];
    }

    /** @return array<string, mixed> */
    private function empty(string $badge, string $detail): array
    {
        return [
            'available' => false,
            'source' => ['key' => 'technical-health', 'title' => 'Salud tecnica SEO', 'badge' => $badge, 'detail' => $detail],
            'stats' => [],
            'rows' => new Collection,
            'visible_count' => 0,
            'total_count' => 0,
        ];
    }
}
