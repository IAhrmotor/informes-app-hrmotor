<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsOverflowRuleWithPollTest extends TestCase
{
    use CreatesCallDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-25 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_overflow_rule_uses_poll_for_web_and_google_maps(): void
    {
        $this->callRow('case-a', $this->portalSupport('Coches.net', 'contact_center'));
        $this->callRow('case-b', $this->portalSupport('Web', 'customer_service', 'poll: 1'));
        $this->callRow('case-c', $this->portalSupport('Web', 'customer_service', 'Poll: 2'));
        $this->callRow('case-d', $this->portalSupport('Web', 'customer_service'));
        $this->callRow('case-e', $this->portalSupport('Web', 'customer_service', 'poll: 3'));
        $this->callRow('case-f', $this->portalSupport('Google Maps', 'contact_center', 'poll: 1'));
        $this->callRow('case-g', $this->portalSupport('Google Maps', 'contact_center', 'poll: 3'));
        $this->callRow('case-h', array_merge($this->portalSupport('Coches.net', 'contact_center'), [
            'call_status' => 'not_answered',
            'result_raw' => 'ABANDONED',
            'is_answered' => false,
            'is_lost' => true,
        ]));
        $this->callRow('case-i', [
            'portales_raw' => null,
            'call_origin' => 'commercial_direct',
            'portal_resolved' => 'Comercial directo',
            'operational_team' => 'contact_center',
            'call_status' => 'answered',
            'result_raw' => 'ANSWERED',
        ]);

        $this->artisan('reports:reprocess-calls-classification')->assertExitCode(0);

        $expected = [
            'case-a' => true,
            'case-b' => true,
            'case-c' => true,
            'case-d' => true,
            'case-e' => false,
            'case-f' => true,
            'case-g' => false,
            'case-h' => false,
            'case-i' => false,
        ];

        foreach ($expected as $id => $isOverflow) {
            $this->assertSame($isOverflow, SalesforceCall::where('salesforce_id', $id)->firstOrFail()->is_overflow, $id);
        }
    }

    private function portalSupport(string $portal, string $team, ?string $description = null): array
    {
        return [
            'portales_raw' => $portal,
            'call_origin' => 'portal',
            'portal_resolved' => $portal,
            'operational_team' => $team,
            'call_status' => 'answered',
            'result_raw' => 'ANSWERED',
            'description' => $description,
        ];
    }
}
