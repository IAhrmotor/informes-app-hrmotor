<?php

namespace App\Services\Reports\CallCenterCommissions;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceTasacion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CallCenterCommissionDashboardService
{
    private const GERMAN_AGENT_NAME = 'German Olsen';

    private const FACILITEA_OWNER_NAMES = [
        'vanesa sanjuan',
        'vanesa san juan',
        'vanessa sanjuan',
        'vanessa san juan',
    ];

    private array $tasacionOpportunityCache = [];

    public function build(?string $month, ?string $contractFrom = null, ?string $contractTo = null): array
    {
        [$selectedMonth, $monthWarning] = $this->resolveMonth($month);
        [$periodStart, $periodEnd, $contractFilterWarning] = $this->resolveContractDateRange(
            $selectedMonth,
            $contractFrom,
            $contractTo
        );
        $issues = $this->blockingIssues();
        $warnings = [];

        if ($monthWarning !== null) {
            $warnings[] = $monthWarning;
        }

        if ($contractFilterWarning !== null) {
            $warnings[] = $contractFilterWarning;
        }

        $summaryRows = [];
        $diagnostics = [
            'monthly_opportunities' => 0,
            'monthly_tasaciones' => 0,
            'purchases_count' => 0,
            'sales_count' => 0,
            'changes_count' => 0,
            'german_negotiations_count' => 0,
            'facilitea_count' => 0,
            'missing_commission_count' => 0,
            'missing_captador_count' => 0,
        ];

        if ($issues === []) {
            $monthlyOpportunities = $this->monthlyOpportunities($periodStart, $periodEnd)->get();
            $diagnostics['monthly_opportunities'] = $monthlyOpportunities->count();
            [$summaryRows, $rowWarnings, $diagnostics] = $this->buildSummaryRows($monthlyOpportunities, $diagnostics);
            $warnings = array_values(array_filter([...$warnings, ...$rowWarnings]));

            if ($this->tasacionesSyncAvailable()) {
                $monthlyTasaciones = $this->monthlyTasaciones($periodStart, $periodEnd)->get();
                $diagnostics['monthly_tasaciones'] = $monthlyTasaciones->count();
                [$summaryRows, $tasacionWarnings, $diagnostics] = $this->appendGermanNegotiationsFromTasaciones(
                    $summaryRows,
                    $monthlyTasaciones,
                    $periodStart,
                    $periodEnd,
                    $diagnostics
                );
                $warnings = array_values(array_filter([...$warnings, ...$tasacionWarnings]));
            } else {
                $warnings[] = 'Negociaciones German depende de salesforce_tasaciones. Ejecuta salesforce:sync-tasaciones para completar ese bloque.';
            }
        }

        $warnings = array_values(array_unique(array_filter($warnings)));

        return [
            'ready' => $issues === [],
            'month' => $selectedMonth->format('Y-m'),
            'month_label' => $selectedMonth->translatedFormat('F Y'),
            'contract_from' => $periodStart->toDateString(),
            'contract_to' => $periodEnd->subDay()->toDateString(),
            'issues' => $issues,
            'warnings' => $warnings,
            'diagnostics' => $diagnostics,
            'summary_rows' => $summaryRows,
        ];
    }

    public function missingCaptadorAudit(?string $month, ?string $contractFrom = null, ?string $contractTo = null): array
    {
        [$selectedMonth, $monthWarning] = $this->resolveMonth($month);
        [$periodStart, $periodEnd, $contractFilterWarning] = $this->resolveContractDateRange(
            $selectedMonth,
            $contractFrom,
            $contractTo
        );
        $issues = $this->blockingIssues();
        $warnings = array_values(array_filter([$monthWarning, $contractFilterWarning]));
        $rows = collect();

        if ($issues === []) {
            $rows = $this->missingCaptadorAuditRowsFromCollection(
                $this->monthlyOpportunities($periodStart, $periodEnd)->get()
            );
        }

        return [
            'ready' => $issues === [],
            'month' => $selectedMonth->format('Y-m'),
            'contract_from' => $periodStart->toDateString(),
            'contract_to' => $periodEnd->subDay()->toDateString(),
            'issues' => $issues,
            'warnings' => $warnings,
            'rows' => $rows->values()->all(),
        ];
    }

    private function buildSummaryRows(Collection $monthlyOpportunities, array $diagnostics): array
    {
        $rows = [];
        $warnings = [];
        $missingCaptadorAuditRows = $this->missingCaptadorAuditRowsFromCollection($monthlyOpportunities);
        $diagnostics['missing_captador_count'] = $missingCaptadorAuditRows->count();

        foreach ($monthlyOpportunities as $opportunity) {
            if ($this->isPurchaseOperation($opportunity)) {
                $captador = $this->primaryCaptador($opportunity);
                $entry = $this->captadorCommissionEntry($opportunity);
                $agentKey = $this->agentKey($captador);
                $this->ensureRow($rows, $agentKey, $captador);
                $rows[$agentKey]['purchase_commission'] = round($rows[$agentKey]['purchase_commission'] + $entry['amount'], 2);
                $rows[$agentKey]['purchase_count']++;
                $rows[$agentKey]['details']['purchases'][] = [
                    'opportunity_id' => $opportunity->salesforce_id,
                    'opportunity_name' => (string) $opportunity->name,
                    'record_type_name' => (string) $opportunity->record_type_name,
                    'captador' => $captador,
                    'commission_amount' => $entry['amount'],
                    'commission_missing' => $entry['missing'],
                    'contract_signed_date' => optional($opportunity->cv_signed_date)?->toDateString(),
                    'vehicle_to_appraise' => $this->vehicleToAppraise($opportunity),
                    'capture_date' => $this->payloadValue($opportunity, 'Fecha_captador__c'),
                    'account_name' => (string) ($opportunity->account_name ?? ''),
                ];
                $diagnostics['purchases_count']++;
                $this->registerCommissionWarning($rows, $agentKey, $diagnostics, 'Compra/Tasacion sin Comision Captador', $entry['missing']);
            }

            if ($this->isSaleOperation($opportunity)) {
                $captador = $this->primaryCaptador($opportunity);
                $entry = $this->captadorCommissionEntry($opportunity);
                $agentKey = $this->agentKey($captador);
                $this->ensureRow($rows, $agentKey, $captador);
                $rows[$agentKey]['sales_commission'] = round($rows[$agentKey]['sales_commission'] + $entry['amount'], 2);
                $rows[$agentKey]['sales_count']++;
                $rows[$agentKey]['details']['sales'][] = [
                    'opportunity_id' => $opportunity->salesforce_id,
                    'opportunity_name' => (string) $opportunity->name,
                    'record_type_name' => (string) $opportunity->record_type_name,
                    'captador' => $captador,
                    'commission_amount' => $entry['amount'],
                    'commission_missing' => $entry['missing'],
                    'contract_signed_date' => optional($opportunity->cv_signed_date)?->toDateString(),
                    'vehicle_to_appraise' => $this->vehicleToAppraise($opportunity),
                    'vehicle_interest' => $this->vehicleOfInterest($opportunity),
                    'account_name' => (string) ($opportunity->account_name ?? ''),
                    'source' => (string) ($opportunity->opportunity_source_raw ?? ''),
                    'owner_name' => (string) ($opportunity->owner_name ?? ''),
                ];
                $diagnostics['sales_count']++;
                $this->registerCommissionWarning($rows, $agentKey, $diagnostics, 'Venta sin Comision Captador', $entry['missing']);
            }

            if ($this->isChangeOperation($opportunity)) {
                $captador = $this->primaryCaptador($opportunity);
                $entry = $this->captadorCommissionEntry($opportunity);
                $agentKey = $this->agentKey($captador);
                $this->ensureRow($rows, $agentKey, $captador);
                $rows[$agentKey]['changes_commission'] = round($rows[$agentKey]['changes_commission'] + $entry['amount'], 2);
                $rows[$agentKey]['changes_count']++;
                $rows[$agentKey]['details']['changes'][] = [
                    'opportunity_id' => $opportunity->salesforce_id,
                    'opportunity_name' => (string) $opportunity->name,
                    'record_type_name' => (string) $opportunity->record_type_name,
                    'captador' => $captador,
                    'commission_amount' => $entry['amount'],
                    'commission_missing' => $entry['missing'],
                    'contract_signed_date' => optional($opportunity->cv_signed_date)?->toDateString(),
                    'vehicle_to_appraise' => $this->vehicleToAppraise($opportunity),
                    'vehicle_interest' => $this->vehicleOfInterest($opportunity),
                    'account_name' => (string) ($opportunity->account_name ?? ''),
                    'source' => (string) ($opportunity->opportunity_source_raw ?? ''),
                    'owner_name' => (string) ($opportunity->owner_name ?? ''),
                ];
                $diagnostics['changes_count']++;
                $this->registerCommissionWarning($rows, $agentKey, $diagnostics, 'Cambio sin Comision Captador', $entry['missing']);
            }

            if ($this->isFaciliteaOperation($opportunity)) {
                $agentName = $this->displayAgent($opportunity->owner_name ?: 'Vanessa Sanjuan');
                $agentKey = $this->agentKey($agentName);
                $this->ensureRow($rows, $agentKey, $agentName);
                $rows[$agentKey]['facilitea_commission'] = round($rows[$agentKey]['facilitea_commission'] + 5, 2);
                $rows[$agentKey]['facilitea_count']++;
                $rows[$agentKey]['details']['facilitea'][] = [
                    'opportunity_id' => $opportunity->salesforce_id,
                    'owner_name' => (string) ($opportunity->owner_name ?? ''),
                    'delivery_days' => $this->deliveryDays($opportunity),
                    'opportunity_name' => (string) $opportunity->name,
                    'account_name' => (string) ($opportunity->account_name ?? ''),
                    'contract_signed_date' => optional($opportunity->cv_signed_date)?->toDateString(),
                    'coowner_name' => (string) ($opportunity->shared_delivery_name ?? ''),
                    'owner_delegation' => (string) ($this->payloadValue($opportunity, 'Delegacion_del_propietario__c') ?: $opportunity->owner_delegation ?: ''),
                    'vehicle_interest' => $this->vehicleOfInterest($opportunity),
                    'delivery_date' => $this->payloadValue($opportunity, 'OPO_FEC_Fecha_entrega__c'),
                    'commission_amount' => 5.0,
                ];
                $diagnostics['facilitea_count']++;
            }
        }

        $rows = collect($rows)
            ->map(fn (array $row): array => $this->finalizeSummaryRow($row))
            ->sortByDesc('final_total')
            ->values()
            ->all();

        if (($diagnostics['missing_commission_count'] ?? 0) > 0) {
            $warnings[] = 'Hay '.$diagnostics['missing_commission_count'].' operaciones con Comision Captador vacia. Se han computado a 0 EUR y quedan marcadas para revision.';
        }

        return [$rows, $warnings, $diagnostics];
    }

    private function appendGermanNegotiationsFromTasaciones(
        array $summaryRows,
        Collection $tasaciones,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        array $diagnostics
    ): array
    {
        $rows = collect($summaryRows)
            ->keyBy('agent_key')
            ->all();
        $warnings = [];
        $germanCountBefore = $diagnostics['german_negotiations_count'] ?? 0;

        foreach ($tasaciones as $tasacion) {
            if (! $this->isGermanNegotiationTasacion($tasacion, $periodStart, $periodEnd)) {
                continue;
            }

            $agentKey = $this->agentKey(self::GERMAN_AGENT_NAME);
            $this->ensureRow($rows, $agentKey, self::GERMAN_AGENT_NAME);
            $rows[$agentKey]['german_negotiation_commission'] = round($rows[$agentKey]['german_negotiation_commission'] + 5, 2);
            $rows[$agentKey]['german_negotiation_count']++;
            $rows[$agentKey]['details']['german_negotiations'][] = [
                'tasacion_id' => $tasacion->salesforce_id,
                'tasacion_name' => (string) ($tasacion->name ?? ''),
                'opportunity_id' => (string) ($tasacion->opportunity_salesforce_id ?? ''),
                'opportunity_name' => (string) ($tasacion->opportunity_name ?? ''),
                'contract_signed_date' => $this->tasacionEffectiveDate($tasacion),
                'tracking_name' => (string) ($this->tasacionTrackingName($tasacion) ?? ''),
                'negotiation_1' => (string) ($this->tasacionNegotiationValue($tasacion, 1) ?? ''),
                'negotiation_2' => (string) ($this->tasacionNegotiationValue($tasacion, 2) ?? ''),
                'negotiation_3' => (string) ($this->tasacionNegotiationValue($tasacion, 3) ?? ''),
                'negotiation_4' => (string) ($this->tasacionNegotiationValue($tasacion, 4) ?? ''),
                'commission_amount' => 5.0,
            ];
            $diagnostics['german_negotiations_count']++;
        }

        if (($diagnostics['monthly_tasaciones'] ?? 0) === 0) {
            $warnings[] = 'No hay tasaciones sincronizadas en el rango activo para revisar Negociaciones German.';
        } elseif (($diagnostics['german_negotiations_count'] ?? 0) === $germanCountBefore) {
            $warnings[] = 'No hay tasaciones del rango activo con Seguimiento German y Negociaci_n_1__c informado.';
        }

        return [
            collect($rows)
                ->map(fn (array $row): array => $this->finalizeSummaryRow($row))
                ->sortByDesc('final_total')
                ->values()
                ->all(),
            $warnings,
            $diagnostics,
        ];
    }

    private function finalizeSummaryRow(array $row): array
    {
        $automaticTotal = round(
            $row['purchase_commission']
            + $row['sales_commission']
            + $row['changes_commission']
            + $row['german_negotiation_commission']
            + $row['facilitea_commission'],
            2
        );

        $row['automatic_total'] = $automaticTotal;
        $row['manual_adjustment'] = $row['manual_adjustment'] ?? 0.0;
        $row['final_total'] = round($automaticTotal + $row['manual_adjustment'], 2);
        $existingObservations = filled($row['observations'] ?? null)
            ? explode(' | ', (string) $row['observations'])
            : [];
        $warnings = array_merge($existingObservations, $row['warnings'] ?? []);
        $row['observations'] = implode(' | ', array_values(array_unique(array_filter($warnings))));

        foreach (['purchases', 'sales', 'changes', 'german_negotiations', 'facilitea'] as $detailKey) {
            $row['details'][$detailKey] = collect($row['details'][$detailKey])
                ->sortBy([
                    ['contract_signed_date', 'desc'],
                    ['opportunity_name', 'asc'],
                    ['tasacion_name', 'asc'],
                ])
                ->values()
                ->all();
        }

        unset($row['warnings']);

        return $row;
    }

    private function monthlyOpportunities(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return SalesforceOpportunity::query()
            ->where('cv_signed', true)
            ->whereDate('cv_signed_date', '>=', $periodStart->toDateString())
            ->whereDate('cv_signed_date', '<', $periodEnd->toDateString())
            ->whereNotNull('raw_payload');
    }

    private function monthlyTasaciones(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return SalesforceTasacion::query()
            ->where(function (Builder $query) use ($periodStart, $periodEnd): void {
                $query
                    ->where(function (Builder $dateQuery) use ($periodStart, $periodEnd): void {
                        $dateQuery
                            ->whereDate('contract_signed_date', '>=', $periodStart->toDateString())
                            ->whereDate('contract_signed_date', '<', $periodEnd->toDateString());
                    })
                    ->orWhere(function (Builder $dateQuery) use ($periodStart, $periodEnd): void {
                        $dateQuery
                            ->whereDate('created_date', '>=', $periodStart->toDateString())
                            ->whereDate('created_date', '<', $periodEnd->toDateString());
                    });
            });
    }

    private function blockingIssues(): array
    {
        $issues = [];

        if (! Schema::hasTable('salesforce_opportunities')) {
            return ['La tabla local salesforce_opportunities no existe todavia.'];
        }

        foreach ([
            'salesforce_id',
            'name',
            'record_type_name',
            'stage_name',
            'owner_name',
            'owner_delegation',
            'shared_delivery_name',
            'account_name',
            'opportunity_source_raw',
            'cv_signed',
            'cv_signed_date',
            'gestion_de_venta',
            'raw_payload',
        ] as $column) {
            if (! Schema::hasColumn('salesforce_opportunities', $column)) {
                $issues[] = "Falta la columna local salesforce_opportunities.{$column}.";
            }
        }

        return $issues;
    }

    private function tasacionesSyncAvailable(): bool
    {
        if (! Schema::hasTable('salesforce_tasaciones')) {
            return false;
        }

        foreach ([
            'salesforce_id',
            'name',
            'opportunity_salesforce_id',
            'opportunity_name',
            'contract_signed_date',
            'cv_signed',
            'tracking_name',
            'negotiation_1',
            'negotiation_2',
            'negotiation_3',
            'negotiation_4',
            'raw_payload',
        ] as $column) {
            if (! Schema::hasColumn('salesforce_tasaciones', $column)) {
                return false;
            }
        }

        return true;
    }

    private function resolveMonth(?string $month): array
    {
        $lastClosedMonth = CarbonImmutable::now()->startOfMonth()->subMonth();

        if (! preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            return [$lastClosedMonth, null];
        }

        try {
            $selectedMonth = CarbonImmutable::createFromFormat('Y-m', (string) $month)->startOfMonth();
        } catch (\Throwable) {
            return [$lastClosedMonth, null];
        }

        if ($selectedMonth->greaterThanOrEqualTo(CarbonImmutable::now()->startOfMonth())) {
            return [$lastClosedMonth, 'Solo se permiten meses cerrados. Se ha cargado automaticamente el ultimo mes cerrado disponible.'];
        }

        return [$selectedMonth, null];
    }

    private function resolveContractDateRange(
        CarbonImmutable $selectedMonth,
        ?string $contractFrom,
        ?string $contractTo
    ): array {
        $monthStart = $selectedMonth->startOfMonth();
        $monthEndExclusive = $monthStart->addMonth();
        $warning = null;

        try {
            $resolvedStart = filled($contractFrom)
                ? CarbonImmutable::parse($contractFrom)->startOfDay()
                : $monthStart;
            $resolvedEndExclusive = filled($contractTo)
                ? CarbonImmutable::parse($contractTo)->addDay()->startOfDay()
                : $monthEndExclusive;
        } catch (\Throwable) {
            return [$monthStart, $monthEndExclusive, 'El filtro de fecha de contrato no es valido. Se ha cargado el mes completo.'];
        }

        if ($resolvedEndExclusive->lessThanOrEqualTo($resolvedStart)) {
            return [$monthStart, $monthEndExclusive, 'El rango de fecha de contrato no es valido. Se ha cargado el mes completo.'];
        }

        $clampedStart = $resolvedStart->lessThan($monthStart) ? $monthStart : $resolvedStart;
        $clampedEndExclusive = $resolvedEndExclusive->greaterThan($monthEndExclusive) ? $monthEndExclusive : $resolvedEndExclusive;

        if (! $clampedStart->equalTo($resolvedStart) || ! $clampedEndExclusive->equalTo($resolvedEndExclusive)) {
            $warning = 'El filtro de fecha de contrato se ha ajustado al mes cerrado seleccionado.';
        }

        if ($clampedEndExclusive->lessThanOrEqualTo($clampedStart)) {
            return [$monthStart, $monthEndExclusive, 'El filtro de fecha de contrato quedo fuera del mes seleccionado. Se ha cargado el mes completo.'];
        }

        return [$clampedStart, $clampedEndExclusive, $warning];
    }

    private function ensureRow(array &$rows, string $agentKey, string $agentName): void
    {
        if (isset($rows[$agentKey])) {
            return;
        }

        $rows[$agentKey] = [
            'agent_key' => $agentKey,
            'agent_name' => $agentName,
            'purchase_commission' => 0.0,
            'purchase_count' => 0,
            'sales_commission' => 0.0,
            'sales_count' => 0,
            'changes_commission' => 0.0,
            'changes_count' => 0,
            'german_negotiation_commission' => 0.0,
            'german_negotiation_count' => 0,
            'facilitea_commission' => 0.0,
            'facilitea_count' => 0,
            'warnings' => [],
            'details' => [
                'purchases' => [],
                'sales' => [],
                'changes' => [],
                'german_negotiations' => [],
                'facilitea' => [],
            ],
        ];
    }

    private function isPurchaseOperation(SalesforceOpportunity $opportunity): bool
    {
        return $this->isTasacion($opportunity)
            && $this->hasPrimaryCaptador($opportunity)
            && ! (bool) $opportunity->gestion_de_venta;
    }

    private function isSaleOperation(SalesforceOpportunity $opportunity): bool
    {
        return $this->isVenta($opportunity)
            && $this->hasPrimaryCaptador($opportunity)
            && ! (bool) $opportunity->gestion_de_venta;
    }

    private function isChangeOperation(SalesforceOpportunity $opportunity): bool
    {
        return $this->isCambio($opportunity)
            && $this->hasPrimaryCaptador($opportunity)
            && ! (bool) $opportunity->gestion_de_venta;
    }

    private function isGermanNegotiationTasacion(
        SalesforceTasacion $tasacion,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd
    ): bool
    {
        return $this->isGermanName($this->tasacionTrackingName($tasacion))
            && filled($this->tasacionNegotiationValue($tasacion, 1))
            && $this->tasacionFallsWithinPeriod($tasacion, $periodStart, $periodEnd)
            && $this->tasacionAllowsCommission($tasacion);
    }

    private function isFaciliteaOperation(SalesforceOpportunity $opportunity): bool
    {
        if (! $this->ownerIsVanessa($opportunity->owner_name) || (bool) $opportunity->gestion_de_venta) {
            return false;
        }

        if ($this->normalizeText($opportunity->stage_name) === 'cerrada perdida') {
            return false;
        }

        $source = $this->normalizeText($opportunity->opportunity_source_raw);
        $name = $this->normalizeText($opportunity->name);

        return str_contains($source, 'facilitea')
            || str_contains($name, 'facilitea')
            || (bool) $this->payloadValue($opportunity, 'Facturado_Facilitea__c')
            || filled($this->payloadValue($opportunity, 'Numero_Factura_Facilitea__c'));
    }

    private function captadorCommissionEntry(SalesforceOpportunity $opportunity): array
    {
        $rawValue = $this->payloadValue($opportunity, 'Comisi_n_Captador__c');

        if ($rawValue === null || $rawValue === '') {
            return [
                'amount' => 0.0,
                'missing' => true,
            ];
        }

        return [
            'amount' => round((float) $rawValue, 2),
            'missing' => false,
        ];
    }

    private function primaryCaptador(SalesforceOpportunity $opportunity): string
    {
        return $this->displayAgent($this->payloadValue($opportunity, 'Captador__c') ?: 'Sin captador');
    }

    private function vehicleToAppraise(SalesforceOpportunity $opportunity): string
    {
        return (string) ($this->payloadValue($opportunity, 'OPO_BUS_Vehiculo_a_tasar__r.Name')
            ?: $this->payloadValue($opportunity, 'OPO_BUS_Vehiculo_a_tasar__c')
            ?: '');
    }

    private function vehicleOfInterest(SalesforceOpportunity $opportunity): string
    {
        return (string) ($this->payloadValue($opportunity, 'OPP_BUS_Vehiculo_de_interes__r.Name')
            ?: $this->payloadValue($opportunity, 'OPP_BUS_Vehiculo_de_interes__c')
            ?: '');
    }

    private function deliveryDays(SalesforceOpportunity $opportunity): ?int
    {
        $signedDate = optional($opportunity->cv_signed_date)?->toDateString();
        $deliveryDate = $this->payloadValue($opportunity, 'OPO_FEC_Fecha_entrega__c');

        if (! $signedDate || ! $deliveryDate) {
            return null;
        }

        return CarbonImmutable::parse($signedDate)->diffInDays(CarbonImmutable::parse((string) $deliveryDate), false);
    }

    private function hasPrimaryCaptador(SalesforceOpportunity $opportunity): bool
    {
        return filled($this->payloadValue($opportunity, 'Captador__c'));
    }

    private function isCallCenterCandidateWithoutCaptador(SalesforceOpportunity $opportunity): bool
    {
        if ((bool) $opportunity->gestion_de_venta) {
            return false;
        }

        if (! ($this->isTasacion($opportunity) || $this->isVenta($opportunity) || $this->isCambio($opportunity))) {
            return false;
        }

        if (! $this->hasCallCenterSignals($opportunity)) {
            return false;
        }

        return ! $this->hasPrimaryCaptador($opportunity);
    }

    private function hasCallCenterSignals(SalesforceOpportunity $opportunity): bool
    {
        if ($this->isFaciliteaOperation($opportunity)) {
            return true;
        }

        foreach ([
            'Captador__c',
            'Comisi_n_Captador__c',
            'Fecha_captador__c',
            'Captador_2__c',
            'Captador_3__c',
            'Captador_4__c',
            'Fecha_captado_2__c',
            'Fecha_captador_3__c',
            'Fecha_captador_4__c',
        ] as $field) {
            if (filled($this->payloadValue($opportunity, $field))) {
                return true;
            }
        }

        return false;
    }

    private function isTasacion(SalesforceOpportunity $opportunity): bool
    {
        return $this->normalizeRecordType($opportunity->record_type_name) === 'tasacion';
    }

    private function isVenta(SalesforceOpportunity $opportunity): bool
    {
        return $this->normalizeRecordType($opportunity->record_type_name) === 'venta';
    }

    private function isCambio(SalesforceOpportunity $opportunity): bool
    {
        return $this->normalizeRecordType($opportunity->record_type_name) === 'cambio';
    }

    private function normalizeRecordType(?string $recordType): string
    {
        return $this->normalizeText($recordType);
    }

    private function ownerIsVanessa(?string $ownerName): bool
    {
        $normalized = $this->normalizeText($ownerName);

        foreach (self::FACILITEA_OWNER_NAMES as $name) {
            if (str_contains($normalized, $name)) {
                return true;
            }
        }

        return false;
    }

    private function isGermanName(?string $name): bool
    {
        $normalized = $this->normalizeText($name);

        return $normalized !== '' && str_contains($normalized, 'german');
    }

    private function agentKey(?string $value): string
    {
        $normalized = $this->normalizeText($value);

        return $normalized !== '' ? $normalized : 'sin-agente';
    }

    private function displayAgent(?string $value): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', (string) $value));

        return $clean !== '' ? $clean : 'Sin agente';
    }

    private function normalizeText(?string $value): string
    {
        $ascii = Str::ascii((string) $value);

        return trim(Str::lower(preg_replace('/\s+/', ' ', $ascii)));
    }

    private function payloadValue(SalesforceOpportunity $opportunity, string $path): mixed
    {
        $payload = is_array($opportunity->raw_payload) ? $opportunity->raw_payload : [];

        if ($payload === []) {
            return null;
        }

        $segments = explode('.', $path);
        $current = $payload;

        foreach ($segments as $segment) {
            if (! is_array($current)) {
                return null;
            }

            $matchedKey = null;

            foreach ($current as $key => $value) {
                if (Str::lower((string) $key) === Str::lower($segment)) {
                    $matchedKey = $key;
                    break;
                }
            }

            if ($matchedKey === null) {
                return null;
            }

            $current = $current[$matchedKey];
        }

        return $current;
    }

    private function payloadHasKey(SalesforceOpportunity $opportunity, string $path): bool
    {
        $payload = is_array($opportunity->raw_payload) ? $opportunity->raw_payload : [];

        if ($payload === []) {
            return false;
        }

        $segments = explode('.', $path);
        $current = $payload;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (! is_array($current)) {
                return false;
            }

            $matchedKey = null;

            foreach ($current as $key => $value) {
                if (Str::lower((string) $key) === Str::lower($segment)) {
                    $matchedKey = $key;
                    break;
                }
            }

            if ($matchedKey === null) {
                return false;
            }

            if ($index === $lastIndex) {
                return true;
            }

            $current = $current[$matchedKey];
        }

        return false;
    }

    private function tasacionTrackingName(SalesforceTasacion $tasacion): ?string
    {
        return $this->tasacionPayloadValue($tasacion, 'Seguimiento__c')
            ?? $tasacion->tracking_name;
    }

    private function tasacionEffectiveDate(SalesforceTasacion $tasacion): ?string
    {
        $rawDate = $this->tasacionPayloadValue($tasacion, 'Fecha_firma_contrato__c')
            ?? $this->tasacionPayloadValue($tasacion, 'Oportunidad__r.Fecha_firma_contrato__c')
            ?? $this->tasacionPayloadValue($tasacion, 'Opportunity__r.Fecha_firma_contrato__c')
            ?? $this->tasacionPayloadValue($tasacion, 'RES_BUS_Oportunidad__r.Fecha_firma_contrato__c')
            ?? $this->tasacionPayloadValue($tasacion, 'TAS_BUS_Oportunidad__r.Fecha_firma_contrato__c')
            ?? optional($tasacion->contract_signed_date)?->toDateString()
            ?? optional($this->linkedOpportunityForTasacion($tasacion)?->cv_signed_date)?->toDateString()
            ?? optional($tasacion->created_date)?->toDateString();

        if (! filled($rawDate)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $rawDate)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function tasacionFallsWithinPeriod(
        SalesforceTasacion $tasacion,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd
    ): bool
    {
        $effectiveDate = $this->tasacionEffectiveDate($tasacion);

        if (! $effectiveDate) {
            return false;
        }

        return $effectiveDate >= $periodStart->toDateString()
            && $effectiveDate < $periodEnd->toDateString();
    }

    private function tasacionAllowsCommission(SalesforceTasacion $tasacion): bool
    {
        $explicitCvSigned = $this->tasacionPayloadValue($tasacion, 'Contrato_CV_firmado__c')
            ?? $this->tasacionPayloadValue($tasacion, 'Oportunidad__r.OPO_CAS_Contrato_CV_firmado__c')
            ?? $this->tasacionPayloadValue($tasacion, 'Opportunity__r.OPO_CAS_Contrato_CV_firmado__c')
            ?? $this->tasacionPayloadValue($tasacion, 'RES_BUS_Oportunidad__r.OPO_CAS_Contrato_CV_firmado__c')
            ?? $this->tasacionPayloadValue($tasacion, 'TAS_BUS_Oportunidad__r.OPO_CAS_Contrato_CV_firmado__c');

        if ($explicitCvSigned !== null && $explicitCvSigned !== '') {
            return (bool) $explicitCvSigned;
        }

        $linkedOpportunity = $this->linkedOpportunityForTasacion($tasacion);

        if ($linkedOpportunity !== null) {
            return (bool) $linkedOpportunity->cv_signed;
        }

        return true;
    }

    private function tasacionNegotiationValue(SalesforceTasacion $tasacion, int $index): mixed
    {
        $field = 'negotiation_'.$index;

        return $this->tasacionPayloadValue($tasacion, 'Negociaci_n_'.$index.'__c')
            ?? $this->tasacionPayloadValue($tasacion, 'Negociacion_'.$index.'__c')
            ?? $tasacion->{$field};
    }

    private function tasacionPayloadValue(SalesforceTasacion $tasacion, string $path): mixed
    {
        $payload = is_array($tasacion->raw_payload) ? $tasacion->raw_payload : [];

        if ($payload === []) {
            return null;
        }

        $segments = explode('.', $path);
        $current = $payload;

        foreach ($segments as $segment) {
            if (! is_array($current)) {
                return null;
            }

            $matchedKey = null;

            foreach ($current as $key => $value) {
                if (Str::lower((string) $key) === Str::lower($segment)) {
                    $matchedKey = $key;
                    break;
                }
            }

            if ($matchedKey === null) {
                return null;
            }

            $current = $current[$matchedKey];
        }

        return $current;
    }

    private function linkedOpportunityForTasacion(SalesforceTasacion $tasacion): ?SalesforceOpportunity
    {
        $salesforceId = $tasacion->opportunity_salesforce_id
            ?: $this->tasacionPayloadValue($tasacion, 'Oportunidad__c')
            ?: $this->tasacionPayloadValue($tasacion, 'Opportunity__c')
            ?: $this->tasacionPayloadValue($tasacion, 'RES_BUS_Oportunidad__c')
            ?: $this->tasacionPayloadValue($tasacion, 'TAS_BUS_Oportunidad__c');

        if (! filled($salesforceId)) {
            return null;
        }

        if (array_key_exists((string) $salesforceId, $this->tasacionOpportunityCache)) {
            return $this->tasacionOpportunityCache[(string) $salesforceId];
        }

        $this->tasacionOpportunityCache[(string) $salesforceId] = SalesforceOpportunity::query()
            ->where('salesforce_id', (string) $salesforceId)
            ->first();

        return $this->tasacionOpportunityCache[(string) $salesforceId];
    }

    private function missingCaptadorAuditRowsFromCollection(Collection $monthlyOpportunities): Collection
    {
        return $monthlyOpportunities
            ->filter(fn (SalesforceOpportunity $opportunity): bool => $this->isCallCenterCandidateWithoutCaptador($opportunity))
            ->map(function (SalesforceOpportunity $opportunity): array {
                $signalFields = collect([
                    'Comisi_n_Captador__c',
                    'Fecha_captador__c',
                    'Captador_2__c',
                    'Captador_3__c',
                    'Captador_4__c',
                    'Fecha_captado_2__c',
                    'Fecha_captador_3__c',
                    'Fecha_captador_4__c',
                ])->filter(fn (string $field): bool => filled($this->payloadValue($opportunity, $field)))
                    ->values()
                    ->all();

                return [
                    'opportunity_id' => $opportunity->salesforce_id,
                    'opportunity_name' => (string) $opportunity->name,
                    'record_type_name' => (string) $opportunity->record_type_name,
                    'stage_name' => (string) $opportunity->stage_name,
                    'owner_name' => (string) ($opportunity->owner_name ?? ''),
                    'account_name' => (string) ($opportunity->account_name ?? ''),
                    'contract_signed_date' => optional($opportunity->cv_signed_date)?->toDateString(),
                    'source' => (string) ($opportunity->opportunity_source_raw ?? ''),
                    'signal_fields' => implode(', ', $signalFields),
                ];
            })
            ->sortBy([
                ['contract_signed_date', 'desc'],
                ['record_type_name', 'asc'],
                ['opportunity_name', 'asc'],
            ])
            ->values();
    }

    private function registerCommissionWarning(
        array &$rows,
        string $agentKey,
        array &$diagnostics,
        string $warning,
        bool $shouldRegister
    ): void {
        if (! $shouldRegister) {
            return;
        }

        $rows[$agentKey]['warnings'][] = $warning;
        $diagnostics['missing_commission_count']++;
    }
}
