<?php

namespace App\Services\SeoAnalytics;

final class SeoIntegrationReadinessService
{
    public function __construct(
        private readonly SearchConsoleClient $searchConsole,
        private readonly GoogleAnalyticsClient $analytics,
        private readonly SistrixClient $sistrix,
    ) {}

    /** @return array<int, array{key: string, title: string, detail: string, badge: string}> */
    public function sources(): array
    {
        $salesforceConfigured = $this->salesforceConfigured();

        return [
            $this->source(
                'search-console',
                'Search Console',
                $this->searchConsole->configured(),
                'Pendiente de configurar',
                'Configuración detectada · acceso pendiente de validar'
            ),
            $this->source(
                'salesforce',
                'Salesforce',
                $salesforceConfigured,
                'Configuración Salesforce incompleta',
                'Fuente Salesforce disponible · campo orgánico pendiente de validar'
            ),
            $this->source(
                'ga4',
                'Google Analytics 4',
                $this->analytics->configured(),
                'Pendiente de configurar',
                'Configuración detectada · acceso pendiente de validar'
            ),
            $this->source(
                'sistrix',
                'SISTRIX AI Check',
                $this->sistrix->configured(),
                'Pendiente de conectar',
                'API configurada · acceso básico pendiente de validar'
            ),
        ];
    }

    private function salesforceConfigured(): bool
    {
        $mode = config('salesforce.auth_mode');
        $base = filled(config('salesforce.token_url'))
            && filled(config('salesforce.client_id'))
            && filled(config('salesforce.client_secret'));

        return $base
            && in_array($mode, ['client_credentials', 'refresh_token'], true)
            && ($mode !== 'refresh_token' || filled(config('salesforce.refresh_token')));
    }

    /** @return array{key: string, title: string, detail: string, badge: string} */
    private function source(
        string $key,
        string $title,
        bool $configured,
        string $missingDetail,
        string $configuredDetail,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'detail' => $configured ? $configuredDetail : $missingDetail,
            'badge' => $configured ? 'Configurada' : 'No configurada',
        ];
    }
}
