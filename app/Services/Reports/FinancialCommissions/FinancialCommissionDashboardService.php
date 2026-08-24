<?php

namespace App\Services\Reports\FinancialCommissions;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\CommercialCommissions\CommissionMonthResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FinancialCommissionDashboardService
{
    private const OPPORTUNITY_COLUMNS = [
        'salesforce_id', 'name', 'stage_name', 'record_type_name',
        'owner_delegation', 'report_owner_delegation', 'opo_for_importe_total',
        'importe_financiado', 'financial_commission', 'financial_discount',
        'garantia_total', 'interest_rate', 'financial_zone',
        'opportunity_record_type_formula', 'cv_signed_date',
    ];

    private const RESPONSIBLE_BY_ZONE = [
        'Zona Carlos' => ['key' => 'zona_carlos', 'label' => 'Carlos'],
        'Zona Cristina' => ['key' => 'zona_cristina', 'label' => 'Cristina'],
        'Zona Irene' => ['key' => 'zona_irene', 'label' => 'Irene'],
        'Zona Nuria' => ['key' => 'zona_nuria', 'label' => 'Nuria'],
    ];

    private const ZONE_BY_DELEGATION = [
        'bilbao' => 'Zona Cristina', 'fontellas' => 'Zona Cristina',
        'gijon' => 'Zona Cristina', 'pamplona' => 'Zona Cristina',
        'san sebastian' => 'Zona Cristina', 'zaragoza' => 'Zona Cristina',
        'a coruna' => 'Zona Cristina', 'valladolid' => 'Zona Cristina',
        'badalona' => 'Zona Cristina', 'manresa' => 'Zona Cristina',
        'girona' => 'Zona Cristina', 'lleida' => 'Zona Cristina',
        'sant boi' => 'Zona Cristina', 'llica de valls' => 'Zona Cristina',
        'barcelona' => 'Zona Cristina', 'elche' => 'Zona Cristina',
        'alcoy' => 'Zona Cristina', 'villareal' => 'Zona Cristina',
        'sedavi' => 'Zona Nuria', 'castellon' => 'Zona Nuria',
        'alcala de guadaira' => 'Zona Carlos', 'badajoz' => 'Zona Carlos',
        'malaga' => 'Zona Carlos', 'malaga centro' => 'Zona Carlos',
        'palma' => 'Zona Carlos', 'sevilla' => 'Zona Carlos',
        'torrejon de ardoz' => 'Zona Carlos', 'rivas' => 'Zona Carlos',
        'call rivas' => 'Zona Carlos', 'alcobendas' => 'Zona Carlos',
        'collado villalba' => 'Zona Carlos', 'valencia' => 'Zona Carlos',
        'murcia' => 'Zona Carlos', 'dos hermanas' => 'Zona Carlos',
        'alicante' => 'Zona Irene', 'paterna' => 'Zona Irene',
    ];

    public function __construct(
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
        private readonly CommissionMonthResolver $monthResolver,
    ) {}

    public function build(?string $month): array
    {
        $selectedMonth = $this->monthResolver->resolve($month);
        $periodStart = $selectedMonth->startOfMonth();
        $periodEnd = $periodStart->addMonth();
        $settings = $this->formulaConfig->forMonth($selectedMonth);
        $issues = $this->blockingIssues();

        if ($issues !== []) {
            return $this->emptyPayload($selectedMonth->format('Y-m'), $selectedMonth->translatedFormat('F Y'), $issues);
        }

        $operations = SalesforceOpportunity::query()
            ->select(self::OPPORTUNITY_COLUMNS)
            ->where('cv_signed_date', '>=', $periodStart->toDateString())
            ->where('cv_signed_date', '<', $periodEnd->toDateString())
            ->whereRaw("LOWER(COALESCE(stage_name, '')) <> ?", ['cerrada perdida'])
            ->where(function ($builder): void {
                $builder
                    ->whereRaw("LOWER(COALESCE(opportunity_record_type_formula, '')) IN (?, ?)", ['venta', 'cambio'])
                    ->orWhere(function ($fallback): void {
                        $fallback
                            ->where(function ($emptyFormula): void {
                                $emptyFormula->whereNull('opportunity_record_type_formula')
                                    ->orWhere('opportunity_record_type_formula', '');
                            })
                            ->whereRaw("LOWER(COALESCE(record_type_name, '')) IN (?, ?)", ['venta', 'cambio']);
                    });
            })
            ->get();

        $financialSettings = $settings['financials'] ?? [];
        $excludedInterestRates = collect($financialSettings['excluded_interest_rates'] ?? [])
            ->map(fn (mixed $value): string => $this->normalizeInterestRate((string) $value))
            ->filter()->values()->all();
        $specialRules = $this->specialResponsibleRules($financialSettings);

        $mapped = $operations
            ->map(fn (SalesforceOpportunity $opportunity): array => $this->mapOperation($opportunity, $excludedInterestRates))
            ->values();
        $included = $mapped->whereNotNull('responsible_key')->values();
        $unknownZoneRows = $mapped->where('unknown_financial_zone', true)->values();
        $unknownZoneRowsWithEconomicImpact = $unknownZoneRows
            ->filter(fn (array $row): bool => $this->hasEconomicImpact($row))
            ->values();

        $summaryRows = $included
            ->groupBy('responsible_key')
            ->map(fn (Collection $rows): array => $this->summarizeResponsible($rows, $financialSettings, $specialRules))
            ->sortBy('summary_label')->values();
        $summaryByResponsible = $summaryRows->keyBy('responsible_key');

        $delegationRows = $included
            ->groupBy(fn (array $row): string => $row['responsible_key'].'|'.$row['delegation_key'])
            ->map(function (Collection $rows) use ($summaryByResponsible): array {
                $parent = $summaryByResponsible->get((string) data_get($rows->first(), 'responsible_key'), []);

                return $this->summarizeDelegation($rows, $parent);
            })
            ->sortBy([['summary_label', 'asc'], ['delegation_name', 'asc']])->values();
        $delegationRows = $this->reconcileDelegationTotals($delegationRows, $summaryByResponsible);

        $profitabilityEligible = $included->where('profitability_eligible', true)->count();
        $operationsWithoutInterest = $included->where('missing_interest_rate', true)->count();
        $warnings = [];
        if ($included->isNotEmpty() && $profitabilityEligible === 0 && $operationsWithoutInterest > 0) {
            $warnings[] = sprintf(
                '%d operaciones del periodo llegan sin Inter_s_elegido__c. El bloque 2 queda a cero hasta que Salesforce exponga o sincronice ese campo.',
                $operationsWithoutInterest
            );
        }
        if ($unknownZoneRows->isNotEmpty()) {
            $warnings[] = sprintf(
                '%d operaciones tienen una zona financiera desconocida y no se han asignado a ningun responsable. Revisa la conciliacion administrativa.',
                $unknownZoneRows->count()
            );
        }

        $integrityIssues = [];
        if ($unknownZoneRowsWithEconomicImpact->isNotEmpty()) {
            $zones = $unknownZoneRowsWithEconomicImpact->pluck('zone_name')->unique()->sort()->implode(', ');
            $integrityIssues[] = sprintf(
                'Dataset financiero no conciliado: %d operaciones de zonas desconocidas (%s) contienen comision o descuento financiero.',
                $unknownZoneRowsWithEconomicImpact->count(),
                $zones
            );
        }

        return [
            'ready' => $integrityIssues === [],
            'month' => $selectedMonth->format('Y-m'),
            'month_label' => $selectedMonth->translatedFormat('F Y'),
            'issues' => $integrityIssues,
            'warnings' => $warnings,
            'diagnostics' => $this->diagnostics($mapped, $included, $summaryRows, $delegationRows),
            'summary_rows' => $summaryRows->all(),
            'delegation_rows' => $delegationRows->all(),
            'detail_rows' => $included
                ->sortBy([['summary_label', 'asc'], ['delegation_name', 'asc'], ['opportunity_id', 'asc']])
                ->values()->all(),
        ];
    }

    private function mapOperation(SalesforceOpportunity $opportunity, array $excludedInterestRates): array
    {
        $explicitZone = trim((string) ($opportunity->financial_zone ?? ''));
        $delegationRaw = trim((string) ($opportunity->owner_delegation ?: $opportunity->report_owner_delegation));
        $fallbackZone = $this->fallbackZoneFromDelegation($delegationRaw);
        $zoneName = $this->normalizeZone($explicitZone !== '' ? $explicitZone : $fallbackZone);
        $responsible = self::RESPONSIBLE_BY_ZONE[$zoneName] ?? null;
        $unknownFinancialZone = $explicitZone !== ''
            && ! $this->isExcludedZone($zoneName)
            && $responsible === null;
        $delegationName = $delegationRaw !== ''
            ? $this->formulaConfig->normalizeDelegationLabel($delegationRaw)
            : 'Sin delegacion';
        $interestRate = trim((string) ($opportunity->interest_rate ?? ''));
        $normalizedInterestRate = $this->normalizeInterestRate($interestRate);
        $excludedInterestRate = $normalizedInterestRate !== ''
            && in_array($normalizedInterestRate, $excludedInterestRates, true);

        return [
            'responsible_key' => $responsible['key'] ?? null,
            'summary_label' => $responsible['label'] ?? 'Sin responsable',
            'zone_name' => $zoneName,
            'zone_source' => $explicitZone !== '' ? 'Salesforce' : ($fallbackZone !== 'Sin Zona' ? 'Delegacion' : 'Sin resolver'),
            'unknown_financial_zone' => $unknownFinancialZone,
            'delegation_name' => $delegationName,
            'delegation_key' => $this->delegationKey($delegationName),
            'opportunity_id' => (string) $opportunity->salesforce_id,
            'opportunity_name' => (string) $opportunity->name,
            'amount_total' => round((float) ($opportunity->opo_for_importe_total ?? 0), 2),
            'amount_financed' => round((float) ($opportunity->importe_financiado ?? 0), 2),
            'financial_commission' => round((float) ($opportunity->financial_commission ?? 0), 2),
            'financial_discount' => round((float) ($opportunity->financial_discount ?? 0), 2),
            'premium_guarantee' => round((float) ($opportunity->garantia_total ?? 0), 2),
            'interest_rate' => $interestRate,
            'profitability_eligible' => $normalizedInterestRate !== '' && ! $excludedInterestRate,
            'missing_interest_rate' => $interestRate === '',
            'excluded_interest_rate' => $excludedInterestRate,
            'profitability_reason' => $interestRate === ''
                ? 'Tipo de interes vacio'
                : ($excludedInterestRate ? 'Tipo de interes excluido' : 'Incluida'),
        ];
    }

    private function summarizeResponsible(Collection $rows, array $settings, array $specialRules): array
    {
        $metrics = $this->aggregateMetrics($rows);
        $responsibleKey = (string) data_get($rows->first(), 'responsible_key');
        $specialRule = $specialRules[$responsibleKey] ?? null;
        $specialPercent = (float) data_get($specialRule, 'percent', 0);
        $usesSpecialRule = $specialPercent > 0;
        $financedIncentive = $this->resolveIncentive($metrics['financed_percentage'], $settings['financed_percentage_brackets'] ?? []);
        $profitabilityIncentive = $this->resolveIncentive($metrics['profitability_percentage'], $settings['profitability_brackets'] ?? []);
        $guaranteeIncentive = $this->resolveIncentive($metrics['guarantee_percentage'], $settings['guarantee_percentage_brackets'] ?? []);
        $block1 = round($metrics['net_commission'] * $financedIncentive, 2);
        $block2 = round($metrics['valid_financial_benefit'] * $profitabilityIncentive, 2);
        $block3 = round($metrics['premium_guarantee_total'] * $guaranteeIncentive, 2);
        $specialCommission = $usesSpecialRule ? round($metrics['net_commission'] * $specialPercent, 2) : 0.0;

        return [
            ...$metrics,
            'responsible_key' => $responsibleKey,
            'summary_label' => trim((string) data_get($specialRule, 'label')) ?: (string) data_get($rows->first(), 'summary_label'),
            'zone_name' => (string) data_get($rows->first(), 'zone_name'),
            'commission_mode' => $usesSpecialRule ? 'net_percentage' : 'standard_blocks',
            'financed_incentive' => $financedIncentive,
            'block_1_commission' => $usesSpecialRule ? 0.0 : $block1,
            'profitability_incentive' => $usesSpecialRule ? 0.0 : $profitabilityIncentive,
            'block_2_commission' => $usesSpecialRule ? 0.0 : $block2,
            'guarantee_incentive' => $usesSpecialRule ? 0.0 : $guaranteeIncentive,
            'block_3_commission' => $usesSpecialRule ? 0.0 : $block3,
            'special_responsible_percent' => $specialPercent,
            'special_responsible_commission' => $specialCommission,
            'final_commission' => $usesSpecialRule ? $specialCommission : round($block1 + $block2 + $block3, 2),
        ];
    }

    private function summarizeDelegation(Collection $rows, array $parent): array
    {
        $metrics = $this->aggregateMetrics($rows);
        $specialPercent = (float) ($parent['special_responsible_percent'] ?? 0);
        $usesSpecialRule = ($parent['commission_mode'] ?? '') === 'net_percentage';
        $block1 = $usesSpecialRule ? 0.0 : round($metrics['net_commission'] * (float) ($parent['financed_incentive'] ?? 0), 2);
        $block2 = $usesSpecialRule ? 0.0 : round($metrics['valid_financial_benefit'] * (float) ($parent['profitability_incentive'] ?? 0), 2);
        $block3 = $usesSpecialRule ? 0.0 : round($metrics['premium_guarantee_total'] * (float) ($parent['guarantee_incentive'] ?? 0), 2);
        $specialCommission = $usesSpecialRule ? round($metrics['net_commission'] * $specialPercent, 2) : 0.0;

        return [
            ...$metrics,
            'responsible_key' => (string) data_get($rows->first(), 'responsible_key'),
            'summary_label' => (string) ($parent['summary_label'] ?? data_get($rows->first(), 'summary_label')),
            'zone_name' => (string) data_get($rows->first(), 'zone_name'),
            'delegation_name' => (string) data_get($rows->first(), 'delegation_name'),
            'commission_mode' => $usesSpecialRule ? 'net_percentage' : 'standard_blocks',
            'financed_incentive' => (float) ($parent['financed_incentive'] ?? 0),
            'block_1_commission' => $block1,
            'profitability_incentive' => $usesSpecialRule ? 0.0 : (float) ($parent['profitability_incentive'] ?? 0),
            'block_2_commission' => $block2,
            'guarantee_incentive' => $usesSpecialRule ? 0.0 : (float) ($parent['guarantee_incentive'] ?? 0),
            'block_3_commission' => $block3,
            'special_responsible_percent' => $specialPercent,
            'special_responsible_commission' => $specialCommission,
            'final_commission' => $usesSpecialRule ? $specialCommission : round($block1 + $block2 + $block3, 2),
            'rounding_adjustment' => 0.0,
            'opportunity_ids' => $rows->pluck('opportunity_id')->values()->all(),
        ];
    }

    private function aggregateMetrics(Collection $rows): array
    {
        $amountTotal = round((float) $rows->sum('amount_total'), 2);
        $amountFinanced = round((float) $rows->sum('amount_financed'), 2);
        $financialCommission = round((float) $rows->sum('financial_commission'), 2);
        $financialDiscount = round((float) $rows->sum('financial_discount'), 2);
        $premiumGuarantee = round((float) $rows->sum('premium_guarantee'), 2);
        $profitabilityRows = $rows->where('profitability_eligible', true);
        $validBenefit = round(
            (float) $profitabilityRows->sum('financial_commission') - (float) $profitabilityRows->sum('financial_discount'),
            2
        );
        $validFinanced = round((float) $profitabilityRows->sum('amount_financed'), 2);

        return [
            'operations_count' => $rows->count(),
            'profitability_eligible_operations_count' => $profitabilityRows->count(),
            'profitability_excluded_operations_count' => $rows->count() - $profitabilityRows->count(),
            'amount_total' => $amountTotal,
            'amount_financed' => $amountFinanced,
            'financed_percentage' => $amountTotal > 0 ? round(($amountFinanced / $amountTotal) * 100, 2) : 0.0,
            'financial_commission_total' => $financialCommission,
            'financial_discount_total' => $financialDiscount,
            'net_commission' => round($financialCommission - $financialDiscount, 2),
            'valid_financial_benefit' => $validBenefit,
            'profitability_percentage' => $validFinanced > 0 ? round(($validBenefit / $validFinanced) * 100, 2) : 0.0,
            'premium_guarantee_total' => $premiumGuarantee,
            'guarantee_percentage' => $amountFinanced > 0 ? round(($premiumGuarantee / $amountFinanced) * 100, 2) : 0.0,
        ];
    }

    private function reconcileDelegationTotals(Collection $delegationRows, Collection $summaryByResponsible): Collection
    {
        return $delegationRows
            ->groupBy('responsible_key')
            ->flatMap(function (Collection $rows, string $responsibleKey) use ($summaryByResponsible): Collection {
                $rows = $rows->values();
                $expected = round((float) data_get($summaryByResponsible->get($responsibleKey), 'final_commission', 0), 2);
                $actual = round((float) $rows->sum('final_commission'), 2);
                $difference = round($expected - $actual, 2);

                if ($difference !== 0.0 && $rows->isNotEmpty()) {
                    $lastIndex = $rows->count() - 1;
                    $last = $rows->get($lastIndex);
                    $last['final_commission'] = round((float) $last['final_commission'] + $difference, 2);
                    $last['rounding_adjustment'] = $difference;
                    $rows->put($lastIndex, $last);
                }

                return $rows;
            })
            ->sortBy([['summary_label', 'asc'], ['delegation_name', 'asc']])
            ->values();
    }

    private function diagnostics(
        Collection $mapped,
        Collection $included,
        Collection $summaryRows,
        Collection $delegationRows,
    ): array {
        $excluded = $mapped->whereNull('responsible_key');
        $unknownZones = $mapped->where('unknown_financial_zone', true)
            ->groupBy('zone_name')
            ->map(function (Collection $rows, string $zoneName): array {
                return [
                    'zone_name' => $zoneName,
                    'operations_count' => $rows->count(),
                    'financial_commission' => round((float) $rows->sum('financial_commission'), 2),
                    'financial_discount' => round((float) $rows->sum('financial_discount'), 2),
                    'opportunity_ids' => $rows->pluck('opportunity_id')->sort()->values()->all(),
                ];
            })
            ->sortBy('zone_name')
            ->values();

        return [
            'zones_count' => $summaryRows->count(),
            'universe_operations_count' => $mapped->count(),
            'eligible_operations_count' => $included->count(),
            'excluded_operations_count' => $excluded->count(),
            'profitability_eligible_operations_count' => $included->where('profitability_eligible', true)->count(),
            'profitability_excluded_operations_count' => $included->where('profitability_eligible', false)->count(),
            'operations_without_interest_rate' => $included->where('missing_interest_rate', true)->count(),
            'operations_with_excluded_interest_rate' => $included->where('excluded_interest_rate', true)->count(),
            'general_or_without_zone_operations_count' => $excluded->filter(
                fn (array $row): bool => $this->isExcludedZone($row['zone_name'])
            )->count(),
            'operations_without_delegation_count' => $mapped->where('delegation_name', 'Sin delegacion')->count(),
            'operations_without_responsible_count' => $excluded->count(),
            'unknown_financial_zone_operations_count' => $unknownZones->sum('operations_count'),
            'unknown_financial_zones' => $unknownZones->all(),
            'rounding_adjustments_count' => $delegationRows->where('rounding_adjustment', '!=', 0.0)->count(),
            'rounding_adjustments_total' => round((float) $delegationRows->sum('rounding_adjustment'), 2),
            'financial_commission_universe' => round((float) $mapped->sum('financial_commission'), 2),
            'financial_commission_included' => round((float) $included->sum('financial_commission'), 2),
            'financial_commission_excluded' => round((float) $excluded->sum('financial_commission'), 2),
            'financial_discount_universe' => round((float) $mapped->sum('financial_discount'), 2),
            'financial_discount_included' => round((float) $included->sum('financial_discount'), 2),
            'financial_discount_excluded' => round((float) $excluded->sum('financial_discount'), 2),
        ];
    }

    private function hasEconomicImpact(array $row): bool
    {
        return (float) ($row['financial_commission'] ?? 0) !== 0.0
            || (float) ($row['financial_discount'] ?? 0) !== 0.0;
    }

    private function specialResponsibleRules(array $settings): array
    {
        return collect($settings['special_responsible_net_commission_percentages'] ?? [])
            ->mapWithKeys(function (mixed $rule, mixed $responsibleKey): array {
                $key = trim((string) $responsibleKey);

                return $key === '' ? [] : [$key => [
                    'label' => trim((string) data_get($rule, 'label')),
                    'percent' => max(0, min(1, (float) data_get($rule, 'percent', 0))),
                ]];
            })
            ->filter(fn (array $rule): bool => $rule['percent'] > 0)
            ->all();
    }

    private function resolveIncentive(float $percentage, array $brackets): float
    {
        foreach ($brackets as $bracket) {
            if ($percentage >= (float) ($bracket['min_percent'] ?? 0)) {
                return (float) ($bracket['incentive'] ?? 0);
            }
        }

        return 0.0;
    }

    private function normalizeZone(?string $value): string
    {
        $zone = trim((string) $value);

        return match (Str::of($zone)->ascii()->lower()->trim()->toString()) {
            'zona cristina' => 'Zona Cristina',
            'zona nuria' => 'Zona Nuria',
            'zona carlos' => 'Zona Carlos',
            'zona irene' => 'Zona Irene',
            'general', 'sin zona', '' => 'Sin Zona',
            default => $zone,
        };
    }

    private function fallbackZoneFromDelegation(?string $delegation): string
    {
        return self::ZONE_BY_DELEGATION[$this->delegationKey((string) $delegation)] ?? 'Sin Zona';
    }

    private function delegationKey(string $delegation): string
    {
        return Str::of($this->formulaConfig->normalizeDelegationLabel($delegation))
            ->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')->trim()->toString();
    }

    private function isExcludedZone(string $zoneName): bool
    {
        return in_array(Str::of($zoneName)->ascii()->lower()->trim()->toString(), ['sin zona', 'general'], true);
    }

    private function normalizeInterestRate(string $value): string
    {
        return Str::of($value)->replace(',', '.')->replace('%', '')->trim()->toString();
    }

    private function blockingIssues(): array
    {
        if (! Schema::hasTable('salesforce_opportunities')) {
            return ['La tabla local salesforce_opportunities no existe todavia.'];
        }

        $missing = collect(self::OPPORTUNITY_COLUMNS)
            ->reject(fn (string $column): bool => Schema::hasColumn('salesforce_opportunities', $column))
            ->values()->all();

        return $missing === [] ? [] : [
            'Faltan columnas locales para Financieros en salesforce_opportunities: '.implode(', ', $missing).'. Ejecuta migrate y resync de opportunities.',
        ];
    }

    private function emptyPayload(string $month, string $monthLabel, array $issues): array
    {
        return [
            'ready' => false,
            'month' => $month,
            'month_label' => $monthLabel,
            'issues' => $issues,
            'warnings' => [],
            'diagnostics' => [],
            'summary_rows' => [],
            'delegation_rows' => [],
            'detail_rows' => [],
        ];
    }
}
