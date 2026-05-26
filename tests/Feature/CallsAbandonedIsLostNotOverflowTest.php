<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsAbandonedIsLostNotOverflowTest extends TestCase
{
    use CreatesCallDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_abandoned_is_lost_and_never_overflow(): void
    {
        $this->callRow('abandoned', [
            'portales_raw' => 'Coches.net',
            'result_raw' => 'ABANDONED',
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'operational_team' => 'contact_center',
        ]);

        $this->artisan('reports:reprocess-calls-classification')->assertExitCode(0);

        $call = SalesforceCall::where('salesforce_id', 'abandoned')->firstOrFail();

        $this->assertSame('not_answered', $call->call_status);
        $this->assertFalse($call->is_answered);
        $this->assertTrue($call->is_lost);
        $this->assertFalse($call->is_overflow);
    }
}
