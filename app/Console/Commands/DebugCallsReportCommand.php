<?php

namespace App\Console\Commands;

use App\Models\SalesforceCall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugCallsReportCommand extends Command
{
    protected $signature = 'reports:debug-calls
        {--unclassified : Muestra ejemplos sin clasificar}
        {--portals : Muestra desglose extendido de portales}';

    protected $description = 'Muestra diagnostico de datos sincronizados para Llamadas.';

    public function handle(): int
    {
        $this->info('Diagnostico Llamadas');
        $this->line('Total llamadas: '.SalesforceCall::query()->count());
        $this->line('Min created_date: '.(SalesforceCall::query()->min('created_date') ?: '-'));
        $this->line('Max created_date: '.(SalesforceCall::query()->max('created_date') ?: '-'));

        $this->newLine();
        $this->table(['Portales raw', 'Total'], $this->counts('portales_raw'));
        $this->table(['Portal resuelto', 'Total'], $this->counts('portal_resolved'));
        $this->table(['Origen llamada', 'Total'], $this->counts('call_origin'));
        $this->table(['Resultado raw', 'Total'], $this->counts('result_raw'));
        $this->table(['Estado llamada', 'Total'], $this->counts('call_status'));
        $this->table(['Direccion', 'Total'], $this->counts('direction'));
        $this->table(['Equipo operativo', 'Total'], $this->counts('operational_team'));
        $this->table(['Delegacion', 'Total'], $this->counts('delegation'));
        $this->table(['Zona', 'Total'], $this->counts('zone'));
        $this->table(['Top usuarios/agentes', 'Total'], $this->counts('operational_user_name'));

        if ($this->option('portals')) {
            $this->newLine();
            $this->table(['Portal resuelto', 'Origen', 'Total'], $this->portalOriginCounts());
        }

        if ($this->option('unclassified')) {
            $this->newLine();
            $this->table([
                'salesforce_id',
                'subject',
                'owner_name',
                'portales_raw',
                'portal_resolved',
                'operational_user_name',
                'operational_team',
                'delegation',
                'zone',
            ], $this->unclassifiedExamples());
        }

        return self::SUCCESS;
    }

    private function counts(string $field): array
    {
        return SalesforceCall::query()
            ->select($field, DB::raw('count(*) as total'))
            ->groupBy($field)
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [$row->{$field} ?: 'NULL', $row->total])
            ->all();
    }

    private function portalOriginCounts(): array
    {
        return SalesforceCall::query()
            ->select(['portal_resolved', 'call_origin', DB::raw('count(*) as total')])
            ->groupBy(['portal_resolved', 'call_origin'])
            ->orderByDesc('total')
            ->limit(30)
            ->get()
            ->map(fn ($row) => [$row->portal_resolved ?: 'NULL', $row->call_origin ?: 'NULL', $row->total])
            ->all();
    }

    private function unclassifiedExamples(): array
    {
        return SalesforceCall::query()
            ->where(function ($query): void {
                $query->where('portal_resolved', 'Sin clasificar')
                    ->orWhere('operational_team', 'unclassified')
                    ->orWhere('delegation', 'Sin clasificar')
                    ->orWhere('zone', 'Sin clasificar');
            })
            ->orderByDesc('created_date')
            ->limit(20)
            ->get()
            ->map(fn (SalesforceCall $call) => [
                $call->salesforce_id,
                $call->subject,
                $call->owner_name,
                $call->portales_raw,
                $call->portal_resolved,
                $call->operational_user_name,
                $call->operational_team,
                $call->delegation,
                $call->zone,
            ])
            ->all();
    }
}
