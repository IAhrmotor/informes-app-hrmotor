<?php

namespace App\Console\Commands;

use App\Models\SalesforceCall;
use Illuminate\Console\Command;

class AuditCallsProfileCommand extends Command
{
    protected $signature = 'reports:audit-calls-profile
        {--profile=Pruebas comunidad comercial : Perfil exacto}
        {--from= : Inicio YYYY-MM-DD}
        {--to= : Fin YYYY-MM-DD}
        {--examples=5 : Ejemplos por usuario}';

    protected $description = 'Audita usuarios y llamadas de un perfil sin excluirlos del informe.';

    public function handle(): int
    {
        $profile = trim((string) $this->option('profile'));
        $query = SalesforceCall::query()->where('owner_profile_name', $profile);
        if (filled($this->option('from'))) {
            $query->whereDate('created_date', '>=', $this->option('from'));
        }
        if (filled($this->option('to'))) {
            $query->whereDate('created_date', '<=', $this->option('to'));
        }
        $rows = $query->get();
        $examples = max(1, min((int) $this->option('examples'), 20));

        $this->info("Perfil auditado: {$profile}. No se aplica ninguna exclusión automática.");
        $this->table(
            ['User ID', 'Nombre', 'Volumen', 'Orígenes', 'Equipo actual', 'Task IDs de ejemplo'],
            $rows->groupBy(fn (SalesforceCall $call): string => (string) ($call->operational_user_id ?: $call->owner_id ?: 'sin-id'))
                ->map(function ($calls, string $id) use ($examples): array {
                    return [
                        $id,
                        $calls->pluck('operational_user_name')->filter()->first() ?: $calls->pluck('owner_name')->filter()->first() ?: 'Sin nombre',
                        $calls->count(),
                        $calls->pluck('call_origin')->filter()->unique()->implode(', '),
                        $calls->pluck('operational_team')->filter()->unique()->implode(', '),
                        $calls->pluck('salesforce_id')->take($examples)->implode(', '),
                    ];
                })->values()->all(),
        );

        return self::SUCCESS;
    }
}
