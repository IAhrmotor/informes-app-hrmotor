<?php

namespace Tests\Unit;

use App\Services\Reports\Leads\LeadDashboardAiInsightsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadDashboardAiInsightsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
    }

    protected function tearDown(): void
    {
        config()->set('openai.enabled', false);
        config()->set('openai.api_key', null);

        parent::tearDown();
    }

    public function test_si_openai_esta_desactivado_usa_fallback(): void
    {
        config()->set('openai.enabled', false);

        $result = app(LeadDashboardAiInsightsService::class)->generate($this->payload());

        $this->assertSame('fallback', $result['source']);
        $this->assertNotEmpty($result['insights']);
    }

    public function test_si_openai_devuelve_json_valido_usa_ia(): void
    {
        config()->set('openai.enabled', true);
        config()->set('openai.api_key', 'test-key');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'insights' => [[
                                'titulo' => 'Pendientes elevados',
                                'problema_detectado' => 'Hay demasiados potenciales sin trabajar.',
                                'evidencia' => 'Potenciales sin trabajar: 12.',
                                'recomendacion' => 'Revisar cartera por comercial.',
                                'prioridad' => 'alta',
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $result = app(LeadDashboardAiInsightsService::class)->generate($this->payload());

        $this->assertSame('ai', $result['source']);
        $this->assertSame('Pendientes elevados', $result['insights'][0]['titulo']);
    }

    public function test_si_openai_falla_usa_fallback(): void
    {
        config()->set('openai.enabled', true);
        config()->set('openai.api_key', 'test-key');

        Http::fake(['api.openai.com/*' => Http::response([], 500)]);

        $result = app(LeadDashboardAiInsightsService::class)->generate($this->payload());

        $this->assertSame('fallback', $result['source']);
    }

    public function test_si_openai_devuelve_json_invalido_usa_fallback(): void
    {
        config()->set('openai.enabled', true);
        config()->set('openai.api_key', 'test-key');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'no json']]],
            ]),
        ]);

        $result = app(LeadDashboardAiInsightsService::class)->generate($this->payload());

        $this->assertSame('fallback', $result['source']);
    }

    public function test_no_envia_leads_individuales_solo_payload_agregado(): void
    {
        config()->set('openai.enabled', true);
        config()->set('openai.api_key', 'test-key');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '{"insights":[]}']]],
            ]),
        ]);

        app(LeadDashboardAiInsightsService::class)->generate($this->payload());

        Http::assertSent(function ($request) {
            $body = $request->data();
            $content = data_get($body, 'messages.1.content');

            return str_contains($content, '"kpis"')
                && str_contains($content, '"rankings"')
                && ! str_contains($content, 'salesforce_id')
                && ! str_contains($content, 'CreatedDate')
                && ! str_contains($content, 'RecordType.Name');
        });
    }

    private function payload(): array
    {
        return [
            'periodo_actual' => ['inicio' => '2026-04-14', 'fin' => '2026-05-14'],
            'periodo_comparado' => ['inicio' => '2026-03-15', 'fin' => '2026-04-14'],
            'filtros' => ['tipo_lead' => 'Venta', 'zona' => 'Zona Cataluña'],
            'kpis' => [
                'leads_totales' => 100,
                'convertidos' => 10,
                'conversion_pct' => 10,
                'descartados' => 30,
                'descarte_pct' => 30,
                'potenciales' => 60,
                'potenciales_sin_trabajar' => 12,
                'gestionados' => 45,
                'gestionados_pct' => 45,
            ],
            'comparativa' => [
                'conversion_delta_pp' => -2.1,
                'descarte_delta_pp' => 4.8,
                'gestionados_delta_pp' => -3.5,
                'potenciales_sin_trabajar_delta' => 6,
            ],
            'rankings' => [
                'comerciales_pendientes' => [['comercial' => 'Comercial 1', 'potenciales_sin_trabajar' => 12]],
                'delegaciones_descartes' => [['lead_delegation' => 'Torrejón', 'descartados' => 30]],
                'portales_baja_conversion' => [['portal' => 'Web', 'leads_totales' => 80, 'conversion_pct' => 3]],
            ],
        ];
    }
}
