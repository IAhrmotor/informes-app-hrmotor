<?php

namespace Tests\Feature;

use App\Models\OperationalAlert;
use App\Models\ReportUser;
use App\Services\Operations\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperationalAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerta_se_deduplica_se_resuelve_y_solo_es_visible_para_admin(): void
    {
        $service = app(OperationalAlertService::class);
        $first = $service->open(
            'scheduled_task_failure',
            'high',
            'scheduler',
            'synthetic-task',
            'Fallo técnico sanitizado.',
        );
        $second = $service->open(
            'scheduled_task_failure',
            'high',
            'scheduler',
            'synthetic-task',
            'Fallo técnico sanitizado.',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->occurrences);
        $this->get('/informes/alertas-operativas')
            ->assertOk()
            ->assertSee('Fallo técnico sanitizado.');

        $service->resolve(
            'scheduled_task_failure',
            'scheduler',
            'synthetic-task',
            'Ejecución posterior correcta.',
        );
        $this->assertDatabaseHas('operational_alerts', [
            'id' => $first->id,
            'state' => OperationalAlert::STATE_RESOLVED,
            'resolution' => 'Ejecución posterior correcta.',
        ]);

        $viewer = ReportUser::query()->create([
            'email' => 'alerts-viewer@example.test',
            'password' => Hash::make('synthetic-viewer-password'),
            'role' => ReportUser::ROLE_VIEWER,
            'is_active' => true,
        ]);

        $this->withSession([
            'informes_authenticated' => true,
            'report_user_id' => $viewer->id,
            'report_user_role' => $viewer->role,
        ])->get('/informes/alertas-operativas')->assertForbidden();
    }
}
