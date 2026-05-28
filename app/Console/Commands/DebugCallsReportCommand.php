<?php

namespace App\Console\Commands;

use App\Models\SalesforceCall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugCallsReportCommand extends Command
{
    protected $signature = 'reports:debug-calls
        {--unclassified : Muestra ejemplos sin clasificar}
        {--portals : Muestra desglose extendido de portales}
        {--overflows : Lista ejemplos de desbordes}';

    protected $description = 'Muestra diagnostico de datos sincronizados para Llamadas.';

    public function handle(): int
    {
        $this->info('Diagnostico Llamadas');
        $this->line('Total llamadas: '.SalesforceCall::query()->count());
        $this->line('Atendidas: '.SalesforceCall::query()->where('call_status', 'answered')->count());
        $this->line('No atendidas/perdidas: '.SalesforceCall::query()->where('call_status', 'not_answered')->count());
        $this->line('Abandoned: '.$this->abandonedCount());
        $this->line('Desbordes: '.SalesforceCall::query()->where('is_overflow', true)->count());
        $this->line('Min created_date: '.(SalesforceCall::query()->min('created_date') ?: '-'));
        $this->line('Max created_date: '.(SalesforceCall::query()->max('created_date') ?: '-'));

        $this->newLine();
        $this->table(['Portales raw', 'Total'], $this->counts('portales_raw'));
        $this->table(['Portal resuelto', 'Total'], $this->counts('portal_resolved'));
        $this->table(['Origen llamada', 'Total'], $this->counts('call_origin'));
        $this->table(['Resultado raw', 'Total'], $this->counts('result_raw'));
        $this->table(['Estado llamada', 'Total'], $this->counts('call_status'));
        $this->table(['Direccion', 'Total'], $this->counts('direction'));
        $this->table(['Poll', 'Total'], $this->counts('poll_value'));
        $this->table(['Equipo operativo', 'Total'], $this->counts('operational_team'));
        $this->table(['Delegacion', 'Total'], $this->counts('delegation'));
        $this->table(['Zona', 'Total'], $this->counts('zone'));
        $this->table(['Top usuarios/agentes', 'Total'], $this->counts('operational_user_name'));
        $this->table(['Desbordes por portal', 'Total'], $this->overflowCounts('portal_resolved'));
        $this->table(['Desbordes por equipo', 'Total'], $this->overflowCounts('operational_team'));
        $this->table(['Desbordes por usuario/agente', 'Total'], $this->overflowCounts('operational_user_name'));
        $this->table(['Desbordes por delegacion', 'Total'], $this->overflowCounts('delegation'));
        $this->table(['Desbordes por zona', 'Total'], $this->overflowCounts('zone'));

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

        if ($this->option('overflows')) {
            $this->newLine();
            $this->table([
                'salesforce_id',
                'created_date',
                'subject',
                'portales_raw',
                'portal_resolved',
                'poll_value',
                'call_status',
                'operational_user_name',
                'operational_team',
                'delegation',
                'zone',
                'result_raw',
                'call_origin',
            ], $this->overflowExamples());
        }

        return self::SUCCESS;
    }

    private function abandonedCount(): int
    {
        return SalesforceCall::query()
            ->whereRaw("UPPER(TRIM(COALESCE(result_raw, ''))) = 'ABANDONED'")
            ->count();
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

    private function overflowCounts(string $field): array
    {
        return SalesforceCall::query()
            ->where('is_overflow', true)
            ->select($field, DB::raw('count(*) as total'))
            ->groupBy($field)
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [$row->{$field} ?: 'NULL', $row->total])
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

    private function overflowExamples(): array
    {
        return SalesforceCall::query()
            ->where('is_overflow', true)
            ->orderByDesc('created_date')
            ->limit(20)
            ->get()
            ->map(fn (SalesforceCall $call) => [
                $call->salesforce_id,
                optional($call->created_date)->toDateTimeString(),
                $call->subject,
                $call->portales_raw,
                $call->portal_resolved,
                $call->poll_value,
                $call->call_status,
                $call->operational_user_name,
                $call->operational_team,
                $call->delegation,
                $call->zone,
                $call->result_raw,
                $call->call_origin,
            ])
            ->all();
    }
}
