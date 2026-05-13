<?php

namespace App\Console\Commands;

use App\Models\MonthlyCommercialReportSnapshot;
use App\Models\SalesforceActivity;
use App\Models\SalesforceLead;
use App\Models\SalesforceLeadActivitySummary;
use App\Models\SalesforceUser;
use Illuminate\Console\Command;

class DebugMonthlyCommercialReportCommand extends Command
{
    protected $signature = 'reports:debug-monthly-commercial';

    protected $description = 'Muestra conteos y diagnostico de datos para el informe mensual comercial.';

    public function handle(): int
    {
        $this->info('Diagnostico informe mensual comercial');
        $this->line('salesforce_users count: '.SalesforceUser::query()->count());
        $this->line('salesforce_leads count: '.SalesforceLead::query()->count());
        $this->line('salesforce_activities count: '.SalesforceActivity::query()->count());
        $this->line('salesforce_lead_activity_summaries count: '.SalesforceLeadActivitySummary::query()->count());
        $this->line('monthly_commercial_report_snapshots count: '.MonthlyCommercialReportSnapshot::query()->count());

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
        $this->line('resumen_global.total_leads_30d: '.data_get($payload, 'resumen_global.leads_totales', 0));

        return self::SUCCESS;
    }
}
