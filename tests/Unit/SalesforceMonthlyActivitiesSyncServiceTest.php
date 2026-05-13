<?php

namespace Tests\Unit;

use App\Models\SalesforceActivity;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyActivitiesSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceMonthlyActivitiesSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_tasks_y_events_sin_actividades_sin_who_id(): void
    {
        $client = new class extends SalesforceClient
        {
            public function __construct()
            {
            }

            public function query(string $soql): array
            {
                if (str_contains($soql, 'FROM Task')) {
                    return [[
                        'Id' => '00T1',
                        'WhoId' => '00Q1',
                        'OwnerId' => '005-owner',
                        'Owner' => ['Name' => 'Owner Task'],
                        'CreatedById' => '005-created',
                        'CreatedBy' => ['Name' => 'Created Task'],
                        'CreatedDate' => '2026-05-01T12:00:00.000+0000',
                        'ActivityDate' => '2026-05-01',
                        'Subject' => 'Llamada',
                        'Type' => 'Call',
                        'Status' => 'Completed',
                    ]];
                }

                return [
                    [
                        'Id' => '00U1',
                        'WhoId' => '00Q1',
                        'OwnerId' => '005-owner-event',
                        'Owner' => ['Name' => 'Owner Event'],
                        'CreatedById' => '005-created-event',
                        'CreatedBy' => ['Name' => 'Created Event'],
                        'CreatedDate' => '2026-05-02T12:00:00.000+0000',
                        'ActivityDate' => '2026-05-02',
                        'Subject' => 'Cita',
                        'Type' => 'Meeting',
                    ],
                    [
                        'Id' => '00U2',
                        'WhoId' => null,
                        'CreatedDate' => '2026-05-02T13:00:00.000+0000',
                    ],
                ];
            }
        };

        $service = new SalesforceMonthlyActivitiesSyncService($client);
        $start = CarbonImmutable::parse('2026-03-14 13:37:27', 'UTC');
        $end = CarbonImmutable::parse('2026-05-13 13:37:27', 'UTC');

        $tasks = $service->syncTasks($start, $end);
        $events = $service->syncEvents($start, $end);

        $this->assertSame(1, $tasks['queried']);
        $this->assertSame(1, $tasks['saved']);
        $this->assertSame(2, $events['queried']);
        $this->assertSame(1, $events['saved']);
        $this->assertSame(2, SalesforceActivity::query()->count());
        $this->assertDatabaseHas('salesforce_activities', [
            'salesforce_id' => '00T1',
            'activity_kind' => 'Task',
            'owner_name' => 'Owner Task',
            'created_by_name' => 'Created Task',
        ]);
        $this->assertDatabaseHas('salesforce_activities', [
            'salesforce_id' => '00U1',
            'activity_kind' => 'Event',
            'owner_name' => 'Owner Event',
            'created_by_name' => 'Created Event',
        ]);
    }
}
