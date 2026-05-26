<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsDebugOverflowsTest extends TestCase
{
    use CreatesCallDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_debug_calls_overflows_option_lists_overflow_examples(): void
    {
        $this->callRow('overflow-debug', [
            'subject' => 'Llamada desbordada',
            'portales_raw' => 'Coches.net Alcobendas',
            'call_origin' => 'portal',
            'portal_resolved' => 'Coches.net',
            'result_raw' => 'ANSWERED',
            'call_status' => 'answered',
            'operational_user_name' => 'Vanesa German',
            'operational_team' => 'contact_center',
            'is_overflow' => true,
        ]);

        $this->artisan('reports:debug-calls', ['--overflows' => true])
            ->expectsOutputToContain('Desbordes: 1')
            ->expectsOutputToContain('overflow-debug')
            ->expectsOutputToContain('Coches.net')
            ->assertExitCode(0);
    }
}
