<?php

namespace Tests\Feature;

use App\Services\Reports\CallCenterCommissions\Sync\SalesforceTasacionSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class SalesforceSyncTasacionesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_acepta_rango_explicito_from_to(): void
    {
        $this->mock(SalesforceTasacionSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->withArgs(function ($start, $end): bool {
                    return $start->equalTo(CarbonImmutable::parse('2026-01-01')->startOfDay())
                        && $end->equalTo(CarbonImmutable::parse('2026-02-01')->startOfDay());
                })
                ->andReturn([
                    'queried' => 0,
                    'saved' => 0,
                    'profiles' => ['opportunity_relation'],
                ]);
        });

        $this->artisan('salesforce:sync-tasaciones', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
        ])
            ->expectsOutputToContain('Periodo fin exclusivo: 2026-02-01T00:00:00Z')
            ->expectsOutputToContain('Perfiles SOQL usados: opportunity_relation')
            ->assertExitCode(0);
    }
}
