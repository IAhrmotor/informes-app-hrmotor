<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExposureFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-13 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_excluir_exposicion_elimina_solo_esos_leads_y_no_existe_modo_solo(): void
    {
        SalesforceLead::create([
            'salesforce_id' => '00Q-exp',
            'name' => 'Exposicion',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'portal_text' => 'Exposición',
        ]);
        SalesforceLead::create([
            'salesforce_id' => '00Q-web',
            'name' => 'Web',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'portal_text' => 'Web',
        ]);

        $included = $this->getJson('/informes/leads/data/summary?exposition_mode=with');
        $excluded = $this->getJson('/informes/leads/data/summary?exposition_mode=without');

        $this->assertSame(2, $included->json('kpis.leads_totales'));
        $this->assertSame(1, $excluded->json('kpis.leads_totales'));
        $this->assertStringNotContainsString('only', file_get_contents(resource_path('js/reports/leads-dashboard.js')));
    }
}
