<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceClient;
use App\Services\SeoAnalytics\GoogleAnalyticsClient;
use App\Services\SeoAnalytics\SalesforceLeadMediumFieldResolver;
use App\Services\SeoAnalytics\SearchConsoleClient;
use App\Services\SeoAnalytics\SistrixClient;
use App\Support\IntegrationErrorSanitizer;
use Illuminate\Console\Command;
use Throwable;

class DiagnoseSeoIntegrationsCommand extends Command
{
    protected $signature = 'seo:diagnose-integrations {--live : Ejecuta verificaciones externas exclusivamente read-only}';

    protected $description = 'Diagnostica de forma segura la configuracion y el acceso read-only de las fuentes SEO.';

    public function handle(
        SalesforceClient $salesforce,
        SalesforceLeadMediumFieldResolver $mediumFields,
        SearchConsoleClient $searchConsole,
        GoogleAnalyticsClient $analytics,
        SistrixClient $sistrix,
    ): int {
        $this->components->info($this->option('live')
            ? 'Diagnóstico SEO/Analytics live (solo lectura)'
            : 'Diagnóstico SEO/Analytics de configuración (sin red)');

        $salesforceConfigured = $this->salesforceConfigured();
        $this->configurationSummary($salesforceConfigured, $searchConsole, $analytics, $sistrix);

        if (! $this->option('live')) {
            return self::SUCCESS;
        }

        $failed = false;
        $failed = ! $this->diagnoseSalesforce($salesforceConfigured, $salesforce, $mediumFields) || $failed;
        $failed = ! $this->diagnoseSearchConsole($searchConsole) || $failed;
        $failed = ! $this->diagnoseAnalytics($analytics) || $failed;
        $failed = ! $this->diagnoseSistrix($sistrix) || $failed;

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function configurationSummary(
        bool $salesforceConfigured,
        SearchConsoleClient $searchConsole,
        GoogleAnalyticsClient $analytics,
        SistrixClient $sistrix,
    ): void {
        $this->table(['Fuente', 'Configuración', 'Identificador no secreto'], [
            ['Salesforce', $this->state($salesforceConfigured), 'Lead describe'],
            ['Search Console', $this->state($searchConsole->configured()), $searchConsole->configuredProperty() ?? '-'],
            ['Google Analytics 4', $this->state($analytics->configured()), $analytics->configuredPropertyId() ?? '-'],
            ['SISTRIX', $this->state($sistrix->configured()), '-'],
        ]);
    }

    private function diagnoseSalesforce(
        bool $configured,
        SalesforceClient $client,
        SalesforceLeadMediumFieldResolver $resolver,
    ): bool {
        $this->newLine();
        $this->components->twoColumnDetail('Salesforce', $configured ? 'configurada' : 'pendiente');

        if (! $configured) {
            return true;
        }

        try {
            $result = $resolver->resolve($client->describe('Lead'));

            $this->line('Resultado campo Medio: '.$result['status']);
            $this->line('Campo verificado: '.($result['verified_field'] ?? '-'));
            $this->table(
                ['API name', 'Label', 'Type', 'Picklist', 'Orgánico', 'Valores'],
                collect($result['candidates'])->map(fn (array $field): array => [
                    $field['api_name'],
                    $field['label'],
                    $field['type'],
                    $field['is_picklist'] ? 'sí' : 'no',
                    $field['has_organic'] ? 'encontrado' : 'no encontrado',
                    implode(', ', $field['picklist_values']),
                ])->all()
            );

            return true;
        } catch (Throwable $exception) {
            return $this->reportFailure('Salesforce', $exception);
        }
    }

    private function diagnoseSearchConsole(SearchConsoleClient $client): bool
    {
        $this->newLine();
        $this->components->twoColumnDetail('Search Console', $client->configured() ? 'configurada' : 'pendiente');

        if (! $client->configured()) {
            return true;
        }

        try {
            $result = $client->diagnose();
            $this->line('Property configurada: '.($result['property'] ?? '-'));
            $this->line('Property accesible: '.($result['accessible'] ? 'sí' : 'no'));
            $this->table(
                ['Property accesible', 'Permiso'],
                collect($result['sites'])->map(fn (array $site): array => [
                    $site['property'],
                    $site['permission'],
                ])->all()
            );

            return true;
        } catch (Throwable $exception) {
            return $this->reportFailure('Search Console', $exception);
        }
    }

    private function diagnoseAnalytics(GoogleAnalyticsClient $client): bool
    {
        $this->newLine();
        $this->components->twoColumnDetail('Google Analytics 4', $client->configured() ? 'configurada' : 'pendiente');

        if (! $client->configured()) {
            return true;
        }

        try {
            $result = $client->diagnose();
            $this->line('Property: '.($result['property_id'] ?? '-'));
            $this->line('Property accesible: '.($result['accessible'] ? 'sí' : 'no'));
            $this->line('Metadata: '.($result['metadata'] ? 'accesible' : 'no accesible'));
            $this->line(sprintf('Dimensiones: %d · Métricas: %d', $result['dimensions'], $result['metrics']));
            $this->line('Timezone: '.($result['timezone'] ?? '-'));
            $this->line('Web streams: '.$result['web_stream_count']);
            $this->table(
                ['Stream', 'Tipo', 'Nombre', 'URI web'],
                collect($result['data_streams'])->map(fn (array $stream): array => [
                    $stream['name'],
                    $stream['type'],
                    $stream['display_name'],
                    $stream['default_uri'] ?? '-',
                ])->all()
            );
            $this->line('Key Events: '.($result['key_events'] === [] ? 'ninguno' : implode(', ', $result['key_events'])));

            return true;
        } catch (Throwable $exception) {
            return $this->reportFailure('Google Analytics 4', $exception);
        }
    }

    private function diagnoseSistrix(SistrixClient $client): bool
    {
        $this->newLine();
        $this->components->twoColumnDetail('SISTRIX', $client->configured() ? 'configurada' : 'pendiente de conectar');

        if (! $client->configured()) {
            return true;
        }

        try {
            $result = $client->diagnose();
            $this->line('API SISTRIX: '.($result['api_accessible'] ? 'accesible' : 'no verificada'));
            $this->line('AI Check: pendiente de verificar');

            return true;
        } catch (Throwable $exception) {
            return $this->reportFailure('SISTRIX', $exception);
        }
    }

    private function reportFailure(string $source, Throwable $exception): bool
    {
        $this->error($source.': no accesible.');
        $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

        return false;
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

    private function state(bool $configured): string
    {
        return $configured ? 'OK' : 'PENDIENTE';
    }
}
