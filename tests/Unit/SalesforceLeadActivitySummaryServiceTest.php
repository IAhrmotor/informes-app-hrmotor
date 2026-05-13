<?php

namespace Tests\Unit;

use App\Models\SalesforceActivity;
use App\Models\SalesforceLeadActivitySummary;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceLeadActivitySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceLeadActivitySummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_calcula_primera_y_ultima_actividad_por_created_date(): void
    {
        SalesforceActivity::create([
            'salesforce_id' => '00T2',
            'lead_salesforce_id' => '00Q1',
            'activity_kind' => 'Event',
            'owner_id' => '005-late',
            'owner_name' => 'Late Owner',
            'created_by_id' => '005-late-created',
            'created_by_name' => 'Late Created',
            'created_date' => '2026-05-03 12:00:00',
            'activity_date' => '2026-05-03',
            'subject' => 'Ultima',
        ]);

        SalesforceActivity::create([
            'salesforce_id' => '00T1',
            'lead_salesforce_id' => '00Q1',
            'activity_kind' => 'Task',
            'owner_id' => '005-first',
            'owner_name' => 'First Owner',
            'created_by_id' => '005-first-created',
            'created_by_name' => 'First Created',
            'created_date' => '2026-05-01 12:00:00',
            'activity_date' => '2026-05-01',
            'subject' => 'Primera',
        ]);

        $service = app(SalesforceLeadActivitySummaryService::class);
        $this->assertSame(1, $service->recalculate(['00Q1']));

        $summary = SalesforceLeadActivitySummary::query()->where('lead_salesforce_id', '00Q1')->firstOrFail();

        $this->assertSame(2, $summary->total_actividades);
        $this->assertSame(1, $summary->total_tasks);
        $this->assertSame(1, $summary->total_events);
        $this->assertSame('00T1', $summary->primer_contacto_activity_id);
        $this->assertSame('Task', $summary->primer_contacto_tipo);
        $this->assertSame('Primera', $summary->primer_contacto_subject);
        $this->assertSame('005-first', $summary->primer_contacto_owner_id);
        $this->assertSame('2026-05-03 12:00:00', $summary->fecha_ultima_actividad->format('Y-m-d H:i:s'));
    }
}
