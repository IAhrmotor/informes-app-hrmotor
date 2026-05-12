<?php

namespace App\Services\Reports\Leads;

use App\Models\LeadNormalized;

class LeadQualityService
{
    public function summary(): array
    {
        return LeadNormalized::query()
            ->where('data_quality_status', '!=', 'ok')
            ->selectRaw('data_quality_issue, COUNT(*) as total')
            ->groupBy('data_quality_issue')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'incidencia' => $row->data_quality_issue,
                'registros' => (int) $row->total,
                'accion' => $this->suggestedAction($row->data_quality_issue),
            ])
            ->values()
            ->all();
    }

    private function suggestedAction(?string $issue): string
    {
        return match ($issue) {
            'Formulario sin Remitente Lead' => 'Revisar campo alternativo',
            'Remitente Lead no mapeado' => 'Añadir a tabla maestra',
            'Llamada sin delegación' => 'Revisar centralita',
            'Portal sin grupo portal' => 'Añadir a tabla maestra',
            'Delegación no reconocida' => 'Normalizar',
            'Exposición sin propietario/delegación trabajador' => 'Completar criterio',
            default => 'Revisar dato',
        };
    }
}