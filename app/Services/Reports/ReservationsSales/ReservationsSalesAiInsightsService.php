<?php

namespace App\Services\Reports\ReservationsSales;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ReservationsSalesAiInsightsService
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
            'reservas-ventas-ai-insights:'.md5(json_encode([
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

        if ((float) data_get($kpis, 'oportunidades_caidas_pct', 0) >= 15) {
            $insights[] = $this->insight(
                'Caidas elevadas',
                'Las oportunidades caidas tienen un peso relevante en el periodo seleccionado.',
                'Caidas: '.data_get($kpis, 'oportunidades_caidas', 0).' ('.data_get($kpis, 'oportunidades_caidas_pct', 0).'%).',
                'Revisar los motivos de perdida por comercial, delegacion y portal para detectar patrones de calidad o seguimiento.',
                'alta'
            );
        }

        if ((float) data_get($comparison, 'caidas_delta_pp', 0) > 0) {
            $insights[] = $this->insight(
                'Subida de oportunidades caidas',
                'Las caidas suben frente al periodo comparado.',
                'Variacion de caidas: +'.data_get($comparison, 'caidas_delta_pp', 0).' puntos.',
                'Priorizar el analisis de delegaciones y portales donde aumentan las oportunidades cerradas perdidas.',
                'media'
            );
        }

        if ((float) data_get($comparison, 'cv_firmados_delta_pp', 0) < 0) {
            $insights[] = $this->insight(
                'Descenso de contratos CV firmados',
                'La firma de contratos CV baja frente al periodo comparado.',
                'Variacion de CV firmados: '.data_get($comparison, 'cv_firmados_delta_pp', 0).' puntos.',
                'Revisar oportunidades reservadas que no avanzan a firma y detectar bloqueos por comercial o delegacion.',
                'alta'
            );
        }

        $portal = collect(data_get($rankings, 'portales_baja_conversion', []))->first();
        if ($portal && (int) data_get($portal, 'oportunidades_totales', 0) > 0) {
            $insights[] = $this->insight(
                'Portal con baja firma CV',
                'Un portal concentra volumen con bajo porcentaje de CV firmados.',
                data_get($portal, 'portal').': '.data_get($portal, 'oportunidades_totales').' oportunidades y '.data_get($portal, 'cv_firmados_pct').'% CV firmados.',
                'Revisar la calidad de entrada y el avance de reservas a contrato en ese portal.',
                'media'
            );
        }

        $commercial = collect(data_get($rankings, 'comerciales_caidas', data_get($rankings, 'comerciales_pendientes', [])))->first();
        if ($commercial && (int) data_get($commercial, 'oportunidades_caidas', 0) > 0) {
            $insights[] = $this->insight(
                'Caidas concentradas por comercial',
                'Un comercial acumula un volumen alto de oportunidades caidas.',
                data_get($commercial, 'comercial').': '.data_get($commercial, 'oportunidades_caidas').' caidas.',
                'Revisar cartera, causas de perdida y criterios de seguimiento con ese comercial.',
                'media'
            );
        }

        return array_slice($insights ?: [
            $this->insight(
                'Sin alertas relevantes',
                'No se detectan alertas relevantes con los filtros actuales.',
                'Los KPIs agregados no muestran desviaciones accionables claras.',
                'Mantener seguimiento de reservas vivas, caidas y contratos CV firmados.',
                'baja'
            ),
        ], 0, 5);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Genera entre 3 y 5 conclusiones accionables para Direccion sobre reservas y ventas a partir exclusivamente del JSON recibido.
Cada conclusion debe tener: titulo, problema_detectado, evidencia, recomendacion y prioridad (alta, media o baja).
No inventes datos. No menciones datos que no esten en el payload. Prioriza problemas accionables.
Evita frases genericas. No uses nombres tecnicos ni API names. No llames Opportunity a la seccion; usa oportunidades, reservas o contratos CV.
Responde solo JSON valido con esta forma: {"insights":[{"titulo":"...","problema_detectado":"...","evidencia":"...","recomendacion":"...","prioridad":"alta"}]}.
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
