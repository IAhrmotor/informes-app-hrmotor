<?php

namespace Tests\Feature;

use App\Services\Reports\MonthlyCommercial\Sync\SalesforceLeadActivitySummaryService;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyActivitiesSyncService;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyLeadsSyncService;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyUsersSyncService;
use Mockery;
use Tests\TestCase;

class SalesforceSyncMonthlyCommercialCommandTest extends TestCase
{
    public function test_no_termina_despues_de_usuarios(): void
    {
        $this->mock(SalesforceMonthlyUsersSyncService::class, function ($mock): void {
            $mock->shouldReceive('sync')->once()->andReturn([
                'soql' => 'SELECT Id FROM User',
                'queried' => 1,
                'saved' => 1,
            ]);
        });

        $this->mock(SalesforceMonthlyLeadsSyncService::class, function ($mock): void {
            $mock->shouldReceive('sync')->once()->andReturn([
                'soql' => 'SELECT Id FROM Lead',
                'queried' => 2,
                'saved' => 2,
            ]);
        });

        $this->mock(SalesforceMonthlyActivitiesSyncService::class, function ($mock): void {
            $mock->shouldReceive('syncTasks')->once()->andReturn([
                'soql' => 'SELECT Id FROM Task',
                'queried' => 3,
                'saved' => 3,
            ]);
            $mock->shouldReceive('syncEvents')->once()->andReturn([
                'soql' => 'SELECT Id FROM Event',
                'queried' => 4,
                'saved' => 4,
            ]);
        });

        $this->mock(SalesforceLeadActivitySummaryService::class, function ($mock): void {
            $mock->shouldReceive('recalculateForPeriod')->once()->andReturn(2);
        });

        $this->artisan('salesforce:sync-monthly-commercial', ['--days' => 60])
            ->expectsOutputToContain('Usuarios sincronizados: 1')
            ->expectsOutputToContain('Leads consultados: 2')
            ->expectsOutputToContain('Tasks consultadas: 3')
            ->expectsOutputToContain('Events consultados: 4')
            ->expectsOutputToContain('Summaries por lead generados: 2')
            ->assertSuccessful();
    }
}
