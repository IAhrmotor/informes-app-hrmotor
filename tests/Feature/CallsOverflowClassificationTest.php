<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsOverflowClassificationTest extends TestCase
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

    public function test_overflow_rule_only_marks_answered_non_web_maps_portals_attended_by_support_teams(): void
    {
        $this->callRow('case-a', [
            'portales_raw' => 'Coches.net Alcobendas',
            'call_status' => 'answered',
            'result_raw' => 'ANSWERED',
            'operational_team' => 'contact_center',
        ]);
        $this->callRow('case-b', [
            'portales_raw' => 'Autoscout Zaragoza',
            'call_status' => 'answered',
            'result_raw' => 'ANSWERED',
            'operational_team' => 'customer_service',
        ]);
        $this->callRow('case-c', [
            'portales_raw' => 'Web',
            'call_status' => 'answered',
            'result_raw' => 'ANSWERED',
            'operational_team' => 'customer_service',
        ]);
        $this->callRow('case-d', [
            'portales_raw' => 'Google Maps',
            'call_status' => 'answered',
            'result_raw' => 'ANSWERED',
            'operational_team' => 'contact_center',
        ]);
        $this->callRow('case-e', [
            'portales_raw' => 'Coches.net',
            'call_status' => 'not_answered',
            'result_raw' => 'ABANDONED',
            'is_answered' => false,
            'is_lost' => true,
            'operational_team' => 'contact_center',
        ]);
        $this->callRow('case-f', [
            'portales_raw' => 'Coches.net',
            'call_status' => 'answered',
            'result_raw' => 'ANSWERED',
            'operational_team' => 'commercial',
        ]);

        $this->artisan('reports:reprocess-calls-classification')->assertExitCode(0);

        $this->assertTrue(SalesforceCall::where('salesforce_id', 'case-a')->firstOrFail()->is_overflow);
        $this->assertTrue(SalesforceCall::where('salesforce_id', 'case-b')->firstOrFail()->is_overflow);
        $this->assertFalse(SalesforceCall::where('salesforce_id', 'case-c')->firstOrFail()->is_overflow);
        $this->assertFalse(SalesforceCall::where('salesforce_id', 'case-d')->firstOrFail()->is_overflow);
        $this->assertFalse(SalesforceCall::where('salesforce_id', 'case-e')->firstOrFail()->is_overflow);
        $this->assertFalse(SalesforceCall::where('salesforce_id', 'case-f')->firstOrFail()->is_overflow);
        $this->assertSame('portal_attended_by_support_team', SalesforceCall::where('salesforce_id', 'case-a')->firstOrFail()->overflow_reason);
    }
}
