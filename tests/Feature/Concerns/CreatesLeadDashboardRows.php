<?php

namespace Tests\Feature\Concerns;

use App\Models\SalesforceLead;
use App\Models\SalesforceLeadActivitySummary;

trait CreatesLeadDashboardRows
{
    protected function leadRow(string $id, array $overrides = []): SalesforceLead
    {
        return SalesforceLead::create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-05-20 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'portal_text' => 'Web',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ], $overrides));
    }

    protected function leadActivity(string $leadId, int $total, ?string $lastActivity = null): void
    {
        SalesforceLeadActivitySummary::create([
            'lead_salesforce_id' => $leadId,
            'total_actividades' => $total,
            'total_tasks' => $total,
            'total_events' => 0,
            'fecha_ultima_actividad' => $lastActivity,
        ]);
    }
}
