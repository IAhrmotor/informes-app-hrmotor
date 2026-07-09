<?php

namespace App\Console\Commands;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AuditDelegationDeliveriesCommand extends Command
{
    protected $signature = 'reports:audit-delegation-deliveries
        {month : Mes en formato YYYY-MM}
        {--delegation=* : Delegaciones a auditar tras normalizacion}';

    protected $description = 'Compara entregas por delegacion usando fecha firma, fecha entrega y owner_delegation.';

    public function __construct(
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $month = (string) $this->argument('month');

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('El mes debe tener formato YYYY-MM.');

            return self::INVALID;
        }

        $periodStart = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
        $periodEnd = $periodStart->addMonth();
        $requestedDelegations = collect((array) $this->option('delegation'))
            ->map(fn (string $label) => $this->formulaConfig->normalizeDelegationLabel($label))
            ->filter(fn (string $label) => $label !== '')
            ->values();

        $rows = SalesforceOpportunity::query()
            ->select([
                'salesforce_id',
                'delivery_store',
                'owner_delegation',
                'record_type_name',
                'stage_name',
                'cv_signed_date',
                'raw_payload',
            ])
            ->where('cv_signed', true)
            ->whereRaw("LOWER(COALESCE(stage_name, '')) <> ?", ['cerrada perdida'])
            ->where(function ($query): void {
                $query
                    ->whereRaw("LOWER(COALESCE(record_type_name, '')) = ?", ['venta'])
                    ->orWhereRaw("LOWER(COALESCE(record_type_name, '')) = ?", ['cambio'])
                    ->orWhereRaw("LOWER(COALESCE(name, '')) LIKE ?", ['%facilitea%']);
            })
            ->orderBy('cv_signed_date')
            ->get();

        $stats = [];
        $rawGroups = [];

        foreach ($rows as $row) {
            $deliveryDelegation = $this->formulaConfig->deliveryDelegationLabel($row->delivery_store, $row->owner_delegation);
            $ownerDelegation = $this->formulaConfig->normalizeDelegationLabel($row->owner_delegation);
            $contractDate = optional($row->cv_signed_date)?->toDateString();
            $deliveryDate = data_get($row->raw_payload, 'OPO_FEC_Fecha_entrega__c');

            foreach (array_filter([$deliveryDelegation, $ownerDelegation]) as $delegationLabel) {
                $stats[$delegationLabel] ??= [
                    'delegation' => $delegationLabel,
                    'contract_count' => 0,
                    'delivery_count' => 0,
                    'owner_count' => 0,
                    'property_count' => 0,
                ];
            }

            if ($deliveryDelegation !== '' && $this->dateInRange($contractDate, $periodStart, $periodEnd)) {
                $stats[$deliveryDelegation]['contract_count']++;
            }

            if ($deliveryDelegation !== '' && $this->dateInRange($deliveryDate, $periodStart, $periodEnd)) {
                $stats[$deliveryDelegation]['delivery_count']++;

                $rawKey = ($row->delivery_store !== '' ? $row->delivery_store : '[empty]')
                    .' | owner: '
                    .($row->owner_delegation !== '' ? $row->owner_delegation : '[empty]');
                $rawGroups[$deliveryDelegation][$rawKey] = ($rawGroups[$deliveryDelegation][$rawKey] ?? 0) + 1;
            }

            if ($ownerDelegation !== '' && $this->dateInRange($contractDate, $periodStart, $periodEnd)) {
                $stats[$ownerDelegation]['owner_count']++;
            }

            $propertyDelegation = $this->formulaConfig->normalizeDelegationLabel(
                data_get($row->raw_payload, 'Delegacion_del_propietario__c')
            );

            if ($propertyDelegation !== '') {
                $stats[$propertyDelegation] ??= [
                    'delegation' => $propertyDelegation,
                    'contract_count' => 0,
                    'delivery_count' => 0,
                    'owner_count' => 0,
                    'property_count' => 0,
                ];
            }

            if ($propertyDelegation !== '' && $this->dateInRange($contractDate, $periodStart, $periodEnd)) {
                $stats[$propertyDelegation]['property_count']++;
            }
        }

        $summaryRows = collect($stats)
            ->sortBy(fn (array $row, string $label) => $this->formulaConfig->delegationKey($label))
            ->values();

        if ($requestedDelegations->isNotEmpty()) {
            $summaryRows = $summaryRows
                ->filter(fn (array $row) => $requestedDelegations->contains($row['delegation']))
                ->values();
        }

        if ($summaryRows->isEmpty()) {
            $this->warn('No hay delegaciones para auditar con esos filtros.');

            return self::SUCCESS;
        }

        $this->info('Auditoria entregas delegaciones '.$month);
        $this->table(
            ['Delegacion', 'Fecha firma', 'Fecha entrega', 'Owner delegacion', 'Deleg. propietario'],
            $summaryRows->map(fn (array $row) => [
                $row['delegation'],
                $row['contract_count'],
                $row['delivery_count'],
                $row['owner_count'],
                $row['property_count'],
            ])->all()
        );

        foreach ($summaryRows as $row) {
            $groups = collect($rawGroups[$row['delegation']] ?? [])->sortDesc();

            if ($groups->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->line('Detalle raw fecha entrega: '.$row['delegation']);
            $this->table(
                ['Origen raw', 'Total'],
                $groups->map(fn (int $total, string $label) => [$label, $total])->values()->all()
            );
        }

        return self::SUCCESS;
    }

    private function dateInRange(?string $date, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        return is_string($date)
            && $date >= $periodStart->toDateString()
            && $date < $periodEnd->toDateString();
    }
}
