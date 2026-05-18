<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class LeadDashboardAiInsightsService
{
    public function generate(array $payload): array
    {
        $fallback = fn () => [
            'insights' => $this->fallbackInsights($payload),
            'source' => 'fallback',
        ];

        if (! config('openai.enabled') || blank(config('openai.api_key'))) {
            return $fallback();
        }

        return Cache::remember(
            'lead-dashboard-ai-insights:'.md5(json_encode([
                'payload' => $payload,
                'model' => config('openai.model'),
            ])),
            now()->addMinutes(30),
            function () use ($payload, $fallback): array {
                try {
                    $response = Http::withToken(config('openai.api_key'))
                        ->timeout((int) config('openai.timeout', 30))
                        ->post('https://api.openai.com/v1/chat/completions', [
                            'model' => config('openai.model'),
                            'temperature' => 0.2,
                            'response_format' => ['type' => 'json_object'],
                            'messages' => [
                                ['role' => 'system', 'content' => $this->systemPrompt()],
                                ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                            ],
                        ]);

                    if (! $response->successful()) {
                        return $fallback();
                    }

                    $decoded = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);
                    $insights = $this->normalizeInsights(data_get($decoded, 'insights', []));

                    return $insights === []
                        ? $fallback()
                        : ['insights' => $insights, 'source' => 'ai'];
                } catch (Throwable) {
                    return $fallback();
                }
            }
        );
    }

    public function fallbackInsights(array $payload): array
    {
        $kpis = data_get($payload, 'kpis', []);
        $comparison = data_get($payload, 'comparativa', []);
        $rankings = data_get($payload, 'rankings', []);
        $insights = [];

        if ((int) data_get($kpis, 'potenciales_sin_trabajar', 0) > 0) {
            $insights[] = $this->insight(
                'Potenciales pendientes de gestión',
                'Hay potenciales sin trabajar en el periodo seleccionado.',
                'Potenciales sin trabajar: '.data_get($kpis, 'potenciales_sin_trabajar', 0).'.',
                'Priorizar la revisión por delegación y comercial, empezando por los mayores volúmenes pendientes.',
                'alta'
            );
        }

        if ((float) data_get($comparison, 'conversion_delta_pp', 0) < 0) {
            $insights[] = $this->insight(
                'Caída de conversión',
                'La conversión baja frente al periodo comparado.',
                'Variación de conversión: '.abs((float) data_get($comparison, 'conversion_delta_pp')).' puntos.',
                'Revisar portales con alto volumen y conversión inferior a la media.',
                'alta'
            );
        }

        if ((float) data_get($comparison, 'descarte_delta_pp', 0) > 0) {
            $insights[] = $this->insight(
                'Subida de descartes',
                'El descarte sube frente al periodo comparado.',
                'Variación de descarte: +'.(float) data_get($comparison, 'descarte_delta_pp').' puntos.',
                'Revisar calidad de entrada y criterios de descarte en las delegaciones con mayor concentración.',
                'media'
            );
        }

        $portal = collect(data_get($rankings, 'portales_baja_conversion', []))->first();
        if ($portal && (float) data_get($portal, 'conversion_pct', 100) < (float) data_get($kpis, 'conversion_pct', 0)) {
            $insights[] = $this->insight(
                'Portal con bajo rendimiento',
                'Un portal concentra volumen relevante y baja conversión.',
                data_get($portal, 'portal').': '.data_get($portal, 'leads_totales').' leads y '.data_get($portal, 'conversion_pct').'% de conversión.',
                'Revisar calidad del lead, mensajes de entrada y seguimiento comercial de este portal.',
                'media'
            );
        }

        $commercial = collect(data_get($rankings, 'comerciales_pendientes', []))->first();
        if ($commercial && (int) data_get($commercial, 'potenciales_sin_trabajar', 0) > 0) {
            $insights[] = $this->insight(
                'Cartera pendiente por comercial',
                'Un comercial acumula un volumen alto de pendientes o baja gestión.',
                data_get($commercial, 'comercial').': '.data_get($commercial, 'potenciales_sin_trabajar').' potenciales sin trabajar.',
                'Revisar cartera pendiente, reasignación si procede y actividad de seguimiento reciente.',
                'media'
            );
        }

        return array_slice($insights ?: [
            $this->insight(
                'Sin alertas relevantes',
                'No se detectan alertas relevantes con los filtros actuales.',
                'Los KPIs agregados no muestran desviaciones accionables claras.',
                'Mantener seguimiento de conversión, descartes y potenciales sin trabajar.',
                'baja'
            ),
        ], 0, 5);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Genera entre 3 y 5 conclusiones accionables para Dirección a partir exclusivamente del JSON recibido.
Cada conclusión debe tener: titulo, problema_detectado, evidencia, recomendacion y prioridad (alta, media o baja).
No inventes datos. No menciones datos que no estén en el payload. Prioriza problemas accionables.
Evita frases genéricas. No uses nombres técnicos ni API names. No menciones Task/Event; usa actividad registrada o seguimiento.
Responde solo JSON válido con esta forma: {"insights":[{"titulo":"...","problema_detectado":"...","evidencia":"...","recomendacion":"...","prioridad":"alta"}]}.
PROMPT;
    }

    private function normalizeInsights(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->map(function (mixed $item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $priority = in_array(data_get($item, 'prioridad'), ['alta', 'media', 'baja'], true)
                    ? data_get($item, 'prioridad')
                    : 'media';

                return [
                    'titulo' => trim((string) data_get($item, 'titulo')),
                    'problema_detectado' => trim((string) data_get($item, 'problema_detectado')),
                    'evidencia' => trim((string) data_get($item, 'evidencia')),
                    'recomendacion' => trim((string) data_get($item, 'recomendacion')),
                    'prioridad' => $priority,
                ];
            })
            ->filter(fn (?array $item) => $item
                && $item['titulo'] !== ''
                && $item['problema_detectado'] !== ''
                && $item['evidencia'] !== ''
                && $item['recomendacion'] !== '')
            ->take(5)
            ->values()
            ->all();
    }

    private function insight(string $title, string $problem, string $evidence, string $recommendation, string $priority): array
    {
        return [
            'titulo' => $title,
            'problema_detectado' => $problem,
            'evidencia' => $evidence,
            'recomendacion' => $recommendation,
            'prioridad' => $priority,
        ];
    }
}
