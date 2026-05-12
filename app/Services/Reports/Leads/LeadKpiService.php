<?php

namespace App\Services\Reports\Leads;

use Illuminate\Database\Eloquent\Builder;

class LeadKpiService
{
    public function calculate(Builder $query): array
    {
        $total = (clone $query)->count();
        $converted = (clone $query)->where('is_converted', true)->count();
        $discarded = (clone $query)->where('is_discarded', true)->count();

        return [
            'total_leads' => $total,
            'converted' => $converted,
            'conversion_pct' => $this->percentage($converted, $total),
            'discarded' => $discarded,
            'discard_pct' => $this->percentage($discarded, $total),
            'calls' => (clone $query)->where('channel_direction', 'Llamada')->count(),
            'forms' => (clone $query)->where('channel_direction', 'Formulario')->count(),
            'potentials' => (clone $query)->where('is_potential', true)->count(),
            'without_task_event' => (clone $query)->where('has_task_event', false)->count(),
        ];
    }

    private function percentage(int $value, int $total): ?float
    {
        if ($total === 0) {
            return null;
        }

        return round(($value / $total) * 100, 2);
    }
}