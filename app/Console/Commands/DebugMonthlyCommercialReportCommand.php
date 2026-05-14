<?php

namespace App\Console\Commands;

use App\Models\MonthlyCommercialReportSnapshot;
use App\Models\SalesforceActivity;
use App\Models\SalesforceLead;
use App\Models\SalesforceLeadActivitySummary;
use App\Models\SalesforceUser;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Console\Command;

class DebugMonthlyCommercialReportCommand extends Command
{
    protected $signature = 'reports:debug-monthly-commercial';

    protected $description = 'Muestra conteos y diagnostico de datos para el informe mensual comercial.';

    public function __construct(
        private readonly LeadDelegationNormalizer $delegationNormalizer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Diagnostico informe mensual comercial');
        $this->line('salesforce_users count: '.SalesforceUser::query()->count());
        $this->line('salesforce_leads count: '.SalesforceLead::query()->count());
        $this->line('salesforce_activities count: '.SalesforceActivity::query()->count());
        $this->line('salesforce_lead_activity_summaries count: '.SalesforceLeadActivitySummary::query()->count());
        $this->line('monthly_commercial_report_snapshots count: '.MonthlyCommercialReportSnapshot::query()->count());
        $this->line('salesforce_users con user_delegation: '.SalesforceUser::query()->whereNotNull('user_delegation')->where('user_delegation', '<>', '')->count());

        $this->newLine();
        $this->line('salesforce_leads.created_date min: '.(SalesforceLead::query()->min('created_date') ?? '-'));
        $this->line('salesforce_leads.created_date max: '.(SalesforceLead::query()->max('created_date') ?? '-'));

        $this->newLine();
        $this->line('Conteo por status:');
        $this->table(
            ['status', 'total'],
            SalesforceLead::query()
                ->selectRaw("COALESCE(status, 'Sin status') as status_label, COUNT(*) as total")
                ->groupBy('status_label')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => [$row->status_label, (int) $row->total])
                ->all()
        );

        $this->line('Top 10 portal_text:');
        $this->table(
            ['portal_text', 'total'],
            SalesforceLead::query()
                ->selectRaw("COALESCE(portal_text, 'Sin portal') as portal_label, COUNT(*) as total")
                ->groupBy('portal_label')
                ->orderByDesc('total')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [$row->portal_label, (int) $row->total])
                ->all()
        );

        $this->line('Conteo por activity_kind:');
        $this->table(
            ['activity_kind', 'total'],
            SalesforceActivity::query()
                ->selectRaw("COALESCE(activity_kind, 'Sin tipo') as kind_label, COUNT(*) as total")
                ->groupBy('kind_label')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => [$row->kind_label, (int) $row->total])
                ->all()
        );

        $diagnostics = $this->delegationDiagnostics();

        $this->line('Top 10 valores brutos delegacion del lead:');
        $this->table(['valor_bruto', 'total'], $this->topRows($diagnostics['lead_raw']));

        $this->line('Top 10 delegaciones del lead normalizadas:');
        $this->table(['delegacion_lead', 'total'], $this->topRows($diagnostics['lead_delegations']));

        $this->line('Top 10 zonas del lead normalizadas:');
        $this->table(['zona_lead', 'total'], $this->topRows($diagnostics['lead_zones']));

        $this->line('Top 10 valores brutos delegacion comercial:');
        $this->table(['valor_bruto', 'total'], $this->topRows($diagnostics['commercial_raw']));

        $this->line('Top 10 delegaciones comerciales normalizadas:');
        $this->table(['delegacion_comercial', 'total'], $this->topRows($diagnostics['commercial_delegations']));

        $this->line('Top 10 zonas comerciales normalizadas:');
        $this->table(['zona', 'total'], $this->topRows($diagnostics['commercial_zones']));

        $this->line('Leads sin clasificar por delegacion del lead: '.$diagnostics['unclassified_lead_delegation']);
        $this->line('Leads sin clasificar por delegacion comercial: '.$diagnostics['unclassified_commercial_delegation']);
        $this->printUnmapped('Valores brutos no mapeados delegacion lead:', $diagnostics['lead_unmapped']);
        $this->printUnmapped('Valores brutos no mapeados delegacion comercial:', $diagnostics['commercial_unmapped']);

        $snapshot = MonthlyCommercialReportSnapshot::query()
            ->latest('generated_at')
            ->latest('id')
            ->first();

        $this->newLine();

        if ($snapshot === null) {
            $this->line('Ultimo snapshot: -');

            return self::SUCCESS;
        }

        $payload = $snapshot->payload_json ?? [];

        $this->line('Ultimo snapshot id: '.$snapshot->id);
        $this->line('Ultimo snapshot generated_at: '.($snapshot->generated_at?->toDateTimeString() ?? '-'));
        $this->line('Datos actualizados: '.($snapshot->generated_at?->format('d/m/Y H:i') ?? '-'));
        $this->line('resumen_global.total_leads_30d: '.data_get($payload, 'resumen_global.leads_totales', 0));

        return self::SUCCESS;
    }

    private function delegationDiagnostics(): array
    {
        $users = SalesforceUser::query()->get()->keyBy('salesforce_id');
        $diagnostics = [
            'lead_raw' => [],
            'lead_delegations' => [],
            'lead_zones' => [],
            'lead_unmapped' => [],
            'commercial_raw' => [],
            'commercial_delegations' => [],
            'commercial_zones' => [],
            'commercial_unmapped' => [],
            'unclassified_lead_delegation' => 0,
            'unclassified_commercial_delegation' => 0,
        ];

        SalesforceLead::query()
            ->select([
                'id',
                'status',
                'owner_id',
                'persona_que_trabajo_id',
                'propietario_descarte_id',
                'delegacion_encargada_text',
                'delegacion_encargada',
                'delegacion_encargada_bueno',
            ])
            ->orderBy('id')
            ->chunkById(2000, function (Collection $leads) use (&$diagnostics, $users): void {
                foreach ($leads as $lead) {
                    $leadDelegationRaw = $this->clean($lead->delegacion_encargada_text)
                        ?? $this->clean($lead->delegacion_encargada)
                        ?? $this->clean($lead->delegacion_encargada_bueno);
                    $leadDelegation = $this->delegationNormalizer->normalize($leadDelegationRaw);
                    $this->increment($diagnostics['lead_raw'], $leadDelegationRaw ?: LeadDelegationNormalizer::UNCLASSIFIED);
                    $this->increment($diagnostics['lead_delegations'], $leadDelegation['delegation']);
                    $this->increment($diagnostics['lead_zones'], $leadDelegation['zone']);

                    if (! $leadDelegation['is_classified']) {
                        $diagnostics['unclassified_lead_delegation']++;
                        if ($leadDelegation['raw_unmapped']) {
                            $this->increment($diagnostics['lead_unmapped'], $leadDelegation['raw_unmapped']);
                        }
                    }

                    $managerId = $this->managerId($lead);
                    $commercialRaw = $this->clean(data_get($users->get($managerId), 'user_delegation'));
                    $commercialDelegation = $this->delegationNormalizer->normalize($commercialRaw);

                    $this->increment($diagnostics['commercial_raw'], $commercialRaw ?: LeadDelegationNormalizer::UNCLASSIFIED);
                    $this->increment($diagnostics['commercial_delegations'], $commercialDelegation['delegation']);
                    $this->increment($diagnostics['commercial_zones'], $commercialDelegation['zone']);

                    if (! $commercialDelegation['is_classified']) {
                        $diagnostics['unclassified_commercial_delegation']++;
                        if ($commercialDelegation['raw_unmapped']) {
                            $this->increment($diagnostics['commercial_unmapped'], $commercialDelegation['raw_unmapped']);
                        }
                    }
                }
            });

        return $diagnostics;
    }

    private function managerId(SalesforceLead $lead): ?string
    {
        if ($lead->status === 'Convertido') {
            return $this->clean($lead->persona_que_trabajo_id) ?: $this->clean($lead->owner_id);
        }

        if ($lead->status === 'Descartado') {
            return $this->clean($lead->propietario_descarte_id)
                ?: $this->clean($lead->persona_que_trabajo_id)
                ?: $this->clean($lead->owner_id);
        }

        return $this->clean($lead->owner_id);
    }

    private function topRows(array $counts): array
    {
        arsort($counts);

        return collect($counts)
            ->take(10)
            ->map(fn (int $total, string $label) => [$label, $total])
            ->values()
            ->all();
    }

    private function increment(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    private function printUnmapped(string $title, array $counts): void
    {
        $this->line($title);

        if ($counts === []) {
            $this->line('No hay valores no mapeados relevantes.');

            return;
        }

        $this->table(['valor_bruto', 'total'], $this->topRows($counts));
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

}
