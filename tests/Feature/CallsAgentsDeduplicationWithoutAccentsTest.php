<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsAgentsDeduplicationWithoutAccentsTest extends TestCase
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

    public function test_contact_center_agents_are_grouped_by_canonical_name_without_accents(): void
    {
        $this->callRow('vanesa-1', [
            'operational_user_id' => '005-a',
            'operational_user_name' => 'Vanesa German',
            'destination_agent_name' => 'Vanesa German',
            'operational_team' => 'contact_center',
            'adjusted_duration_seconds' => 40,
            'direction' => 'inbound',
        ]);
        $this->callRow('vanesa-2', [
            'operational_user_id' => '005-b',
            'operational_user_name' => 'Vanesa Germán',
            'destination_agent_name' => 'Vanesa Germán',
            'operational_team' => 'contact_center',
            'adjusted_duration_seconds' => 80,
            'direction' => 'outbound',
        ]);
        $this->callRow('vanesa-3', [
            'operational_user_id' => '005-c',
            'operational_user_name' => 'AG1 - Vanesa Germán',
            'destination_agent_name' => 'AG1 - Vanesa Germán',
            'operational_team' => 'contact_center',
            'call_status' => 'not_answered',
            'is_answered' => false,
            'is_lost' => true,
            'adjusted_duration_seconds' => 200,
            'direction' => 'inbound',
        ]);

        foreach (['Yuleidis Garcia', 'Yuleidis García', 'AG23 - Yuleidis García'] as $index => $name) {
            $this->callRow('yuleidis-'.$index, [
                'operational_user_name' => $name,
                'destination_agent_name' => $name,
                'operational_team' => 'contact_center',
                'adjusted_duration_seconds' => 60,
            ]);
        }

        $rows = collect($this->getJson('/informes/llamadas/data/agents')->assertOk()->json('contact_center'));
        $vanesa = $rows->firstWhere('user_name', 'Vanesa German');
        $yuleidis = $rows->firstWhere('user_name', 'Yuleidis Garcia');

        $this->assertCount(2, $rows);
        $this->assertSame(3, $vanesa['total_calls']);
        $this->assertSame(2, $vanesa['answered']);
        $this->assertSame(1, $vanesa['not_answered']);
        $this->assertSame(2, $vanesa['inbound']);
        $this->assertSame(1, $vanesa['outbound']);
        $this->assertEquals(60.0, $vanesa['average_talk_seconds']);
        $this->assertSame(3, $yuleidis['total_calls']);
    }
}
