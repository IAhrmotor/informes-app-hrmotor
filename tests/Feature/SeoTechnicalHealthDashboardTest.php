<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SeoTechnicalUrl;
use App\Models\SeoTechnicalUrlCheck;
use App\Services\SeoAnalytics\SeoTechnicalHealthSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoTechnicalHealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_without_data_has_no_period_selector_or_external_http(): void
    {
        config(['seo_analytics.technical_health.site_url' => null]);
        Http::fake();

        $this->get('/informes/seo-analytics?section=health&range=90')
            ->assertOk()
            ->assertSee('Sin comprobaciones técnicas disponibles')
            ->assertSee('Pendiente de configurar')
            ->assertDontSee('name="range"', false)
            ->assertDontSee('report-ui-status--critical', false)
            ->assertDontSee('report-ui-status--deviation', false);
        Http::assertNothingSent();
    }

    public function test_health_renders_persisted_metrics_infrastructure_findings_and_escaping(): void
    {
        config(['seo_analytics.technical_health.site_url' => 'https://example.test']);
        $checkedAt = now();
        $url = SeoTechnicalUrl::query()->create([
            'url' => 'https://example.test/?q=<script>alert(1)</script>',
            'url_hash' => hash('sha256', 'synthetic-url'), 'host' => 'example.test',
            'is_active' => true, 'is_strategic' => true, 'is_search_console' => true,
            'in_sitemap' => false, 'first_selected_at' => $checkedAt, 'last_selected_at' => $checkedAt,
            'sitemap_checked_at' => $checkedAt,
        ]);
        SeoTechnicalUrlCheck::query()->create([
            'seo_technical_url_id' => $url->id, 'check_date' => '2026-08-18', 'checked_at' => $checkedAt,
            'final_url' => 'https://example.test/final', 'http_status' => 404, 'redirect_count' => 1,
            'response_time_ms' => 125, 'is_html' => true, 'has_noindex' => true,
            'canonical_url' => 'https://example.test/<script>alert(2)</script>',
            'canonical_count' => 1, 'canonical_matches_final' => false,
            'body_truncated' => true,
            'error_message' => '<script>alert(3)</script>',
        ]);
        ReportSyncRun::query()->create([
            'dataset' => SeoTechnicalHealthSyncService::DATASET, 'source' => 'public_website', 'status' => 'completed',
            'period_start_at' => $checkedAt, 'period_end_at' => $checkedAt, 'source_cutoff_at' => $checkedAt,
            'started_at' => $checkedAt, 'completed_at' => $checkedAt, 'timezone' => 'Europe/Madrid',
            'stats' => [
                'site_host' => 'example.test', 'check_date' => '2026-08-18', 'robots_status' => 200,
                'sitemap_sources' => 1, 'sitemap_documents_checked' => 1, 'sitemap_scan_complete' => true,
                'checked_urls' => 1, 'http_2xx' => 0, 'http_4xx' => 1, 'http_5xx' => 0,
                'redirected_urls' => 1, 'network_errors' => 0, 'noindex_urls' => 1,
            ],
        ]);
        Http::fake();

        $response = $this->get('/informes/seo-analytics?section=health')
            ->assertOk()
            ->assertSee('Infraestructura de rastreo')
            ->assertSee('URLs monitorizadas')
            ->assertSee('Estratégica + Search Console')
            ->assertDontSee('Distinta')
            ->assertSee('125 ms')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('name="range"', false)
            ->assertDontSee('report-ui-status--critical', false)
            ->assertDontSee('report-ui-status--deviation', false);

        $html = $response->getContent();
        $this->assertStringContainsString('aria-label="URLs monitorizadas por salud técnica SEO"', $html);
        $this->assertGreaterThanOrEqual(9, substr_count($html, 'scope="col"'));
        Http::assertNothingSent();
    }

    public function test_failed_or_running_run_keeps_previous_completed_health_data_visible(): void
    {
        config(['seo_analytics.technical_health.site_url' => 'https://example.test']);
        $this->completedRun();
        $this->createRun('failed', now()->addMinute());

        $this->get('/informes/seo-analytics?section=health')
            ->assertOk()
            ->assertSee('Error ultimo sync');

        $this->createRun('running', now()->addMinutes(2));
        $this->get('/informes/seo-analytics?section=health')
            ->assertOk()
            ->assertSee('Sincronizando');
    }

    private function completedRun(): void
    {
        $this->createRun('completed', now(), ['site_host' => 'example.test', 'check_date' => '2026-08-18', 'checked_urls' => 0]);
    }

    /** @param array<string, mixed> $stats */
    private function createRun(string $status, mixed $at, array $stats = ['site_host' => 'example.test']): void
    {
        ReportSyncRun::query()->create([
            'dataset' => SeoTechnicalHealthSyncService::DATASET, 'source' => 'public_website', 'status' => $status,
            'period_start_at' => $at, 'period_end_at' => $at,
            'source_cutoff_at' => $status === 'completed' ? $at : null,
            'started_at' => $at, 'completed_at' => $status === 'running' ? null : $at,
            'timezone' => 'Europe/Madrid', 'stats' => $stats,
        ]);
    }
}
