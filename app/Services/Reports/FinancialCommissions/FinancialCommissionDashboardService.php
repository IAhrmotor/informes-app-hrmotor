<?php

namespace App\Services\Reports\FinancialCommissions;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FinancialCommissionDashboardService
{
    private const OPPORTUNITY_COLUMNS = [
        'salesforce_id',
        'name',
        'stage_name',
        'record_type_name',
        'owner_delegation',
        'opo_for_importe_total',
        'importe_financiado',
        'financial_commission',
        'financial_discount',
        'beneficio_financiacion_comercial',
        'garantia_total',
        'interest_rate',
        'financial_zone',
        'opportunity_record_type_formula',
        'cv_signed_date',
    ];

    private const ZONE_BY_DELEGATION = [
        'bilbao' => 'Zona Cristina',
        'fontellas' => 'Zona Cristina',
        'gijon' => 'Zona Cristina',
        'pamplona' => 'Zona Cristina',
        'san sebastian' => 'Zona Cristina',
        'zaragoza' => 'Zona Cristina',
        'a coruna' => 'Zona Cristina',
        'valladolid' => 'Zona Cristina',
        'badalona' => 'Zona Cristina',
        'manresa' => 'Zona Cristina',
        'girona' => 'Zona Cristina',
        'lleida' => 'Zona Cristina',
        'sant boi' => 'Zona Cristina',
        'llica de valls' => 'Zona Cristina',
        'barcelona' => 'Zona Cristina',
        'elche' => 'Zona Cristina',
        'alcoy' => 'Zona Cristina',
        'villareal' => 'Zona Cristina',
        'sedavi' => 'Zona Nuria',
        'castellon' => 'Zona Nuria',
        'alcala de guadaira' => 'Zona Carlos',
        'badajoz' => 'Zona Carlos',
        'malaga' => 'Zona Carlos',
        'malaga centro' => 'Zona Carlos',
        'palma' => 'Zona Carlos',
        'sevilla' => 'Zona Carlos',
        'torrejon de ardoz' => 'Zona Carlos',
        'rivas' => 'Zona Carlos',
        'call rivas' => 'Zona Carlos',
        'alcobendas' => 'Zona Carlos',
        'collado villalba' => 'Zona Carlos',
        'valencia' => 'Zona Carlos',
        'murcia' => 'Zona Carlos',
        'dos hermanas' => 'Zona Carlos',
        'alicante' => 'Zona Irene',
        'paterna' => 'Zona Irene',
    ];

    public function __construct(
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
    ) {
    }

    public function build(?string $month): array
    {
        $selectedMonth = $this->formulaConfig->resolveSelectedMonth($month);
        $periodStart = $selectedMonth->startOfMonth();
        $periodEnd = $periodStart->addMonth();
        $settings = $this->formulaConfig->forMonth($selectedMonth);
        $issues = $this->blockingIssues();

        if ($issues !== []) {
            return [
                'ready' => false,
                'month' => $selectedMonth->format('Y-m'),
                'month_label' => $selectedMonth->translatedFormat('F Y'),
                'issues' => $issues,
                'warnings' => [],
                'diagnostics' => [],
                'summary_rows' => [],
            ];
        }

        $operations = SalesforceOpportunity::query()
            ->select(self::OPPORTUNITY_COLUMNS)
            ->whereDate('cv_signed_date', '>=', $periodStart->toDateString())
            ->whereDate('cv_signed_date', '<', $periodEnd->toDateString())
            ->whereRaw("LOWER(COALESCE(stage_name, '')) <> ?", ['cerrada perdida'])
            ->where(function ($builder): void {
                $builder
                    ->whereRaw("LOWER(COALESCE(opportunity_record_type_formula, '')) = ?", ['venta'])
                    ->orWhereRaw("LOWER(COALESCE(opportunity_record_type_formula, '')) = ?", ['cambio'])
                    ->orWhere(function ($nested): void {
                        $nested
                            ->whereNull('opportunity_record_type_formula')
                            ->where(function ($fallback): void {
                                $fallback
                                    ->whereRaw("LOWER(COALESCE(record_type_name, '')) = ?", ['venta'])
                                    ->orWhereRaw("LOWER(COALESCE(record_type_name, '')) = ?", ['cambio']);
                            });
                    });
            })
            ->get();

        $excludedInterestRates = collect($settings['financials']['excluded_interest_rates'] ?? [])
            ->map(fn (mixed $value) => $this->normalizeInterestRate((string) $value))
            ->filter()
            ->values()
            ->all();

        $prepared = $operations
            ->map(fn (SalesforceOpportunity $opportunity): array => $this->mapOperation($opportunity, $excludedInterestRates))
            ->filter(fn (array $row): bool => ! $this->isExcludedZone($row['zone_name']))
            ->values();

        $summaryRows = $prepared
            ->groupBy('zone_name')
            ->map(fn (Collection $rows, string $zoneName): array => $this->summarizeZone($zoneName, $rows, $settings['financials'] ?? []))
            ->sortBy('zone_name')
            ->values()
            ->all();

        $excludedByZone = $prepared
            ->groupBy('zone_name')
            ->map(fn (Collection $rows): int => $rows->where('profitability_eligible', false)->count());

        return [
            'ready' => true,
            'month' => $selectedMonth->format('Y-m'),
            'month_label' => $selectedMonth->translatedFormat('F Y'),
            'issues' => [],
            'warnings' => [],
            'diagnostics' => [
                'zones_count' => count($summaryRows),
                'eligible_operations_count' => $prepared->count(),
                'profitability_eligible_operations_count' => $prepared->where('profitability_eligible', true)->count(),
                'profitability_excluded_operations_count' => $prepared->where('profitability_eligible', false)->count(),
                'operations_without_interest_rate' => $prepared->where('missing_interest_rate', true)->count(),
                'operations_with_excluded_interest_rate' => $prepared->where('excluded_interest_rate', true)->count(),
                'excluded_by_zone' => $excludedByZone->all(),
            ],
            'summary_rows' => $summaryRows,
        ];
    }

    private function mapOperation(SalesforceOpportunity $opportunity, array $excludedInterestRates): array
    {
        $zoneName = $this->normalizeZone(
            $opportunity->financial_zone
                ?: $this->fallbackZoneFromDelegation($opportunity->owner_delegation)
        );
        $interestRate = trim((string) ($opportunity->interest_rate ?? ''));
        $normalizedInterestRate = $this->normalizeInterestRate($interestRate);
        $excludedInterestRate = $normalizedInterestRate !== '' && in_array($normalizedInterestRate, $excludedInterestRates, true);

        return [
            'zone_name' => $zoneName,
            'amount_total' => round((float) ($opportunity->opo_for_importe_total ?? 0), 2),
            'amount_financed' => round((float) ($opportunity->importe_financiado ?? 0), 2),
            'financial_commission' => round((float) ($opportunity->financial_commission ?? 0), 2),
            'financial_discount' => round((float) ($opportunity->financial_discount ?? 0), 2),
            'premium_guarantee' => round((float) ($opportunity->garantia_total ?? 0), 2),
            'interest_rate' => $interestRate,
            'profitability_eligible' => $normalizedInterestRate !== '' && ! $excludedInterestRate,
            'missing_interest_rate' => $interestRate === '',
            'excluded_interest_rate' => $excludedInterestRate,
        ];
    }

    private function summarizeZone(string $zoneName, Collection $rows, array $settings): array
    {
        $amountTotal = round((float) $rows->sum('amount_total'), 2);
        $amountFinanced = round((float) $rows->sum('amount_financed'), 2);
        $financialCommissionTotal = round((float) $rows->sum('financial_commission'), 2);
        $financialDiscountTotal = round((float) $rows->sum('financial_discount'), 2);
        $netCommission = round($financialCommissionTotal - $financialDiscountTotal, 2);
        $premiumGuaranteeTotal = round((float) $rows->sum('premium_guarantee'), 2);

        $profitabilityRows = $rows->where('profitability_eligible', true)->values();
        $validFinancialCommissionTotal = round((float) $profitabilityRows->sum('financial_commission'), 2);
        $validFinancialDiscountTotal = round((float) $profitabilityRows->sum('financial_discount'), 2);
        $validFinancialBenefit = round($validFinancialCommissionTotal - $validFinancialDiscountTotal, 2);
        $validFinancedAmount = round((float) $profitabilityRows->sum('amount_financed'), 2);

        $financedPercentage = $amountTotal > 0
            ? round(($amountFinanced / $amountTotal) * 100, 2)
            : 0.0;
        $profitabilityPercentage = $validFinancedAmount > 0
            ? round(($validFinancialBenefit / $validFinancedAmount) * 100, 2)
            : 0.0;
        $guaranteePercentage = $amountFinanced > 0
            ? round(($premiumGuaranteeTotal / $amountFinanced) * 100, 2)
            : 0.0;

        $financedIncentive = $this->resolveIncentive(
            $financedPercentage,
            $settings['financed_percentage_brackets'] ?? []
        );
        $profitabilityIncentive = $this->resolveIncentive(
            $profitabilityPercentage,
            $settings['profitability_brackets'] ?? []
        );
        $guaranteeIncentive = $this->resolveIncentive(
            $guaranteePercentage,
            $settings['guarantee_percentage_brackets'] ?? []
        );

        $block1Commission = round($netCommission * $financedIncentive, 2);
        $block2Commission = round($validFinancialBenefit * $profitabilityIncentive, 2);
        $block3Commission = round($premiumGuaranteeTotal * $guaranteeIncentive, 2);
        $finalCommission = round($block1Commission + $block2Commission + $block3Commission, 2);

        return [
            'zone_name' => $zoneName,
            'operations_count' => $rows->count(),
            'profitability_eligible_operations_count' => $profitabilityRows->count(),
            'profitability_excluded_operations_count' => $rows->count() - $profitabilityRows->count(),
            'amount_total' => $amountTotal,
            'amount_financed' => $amountFinanced,
            'financed_percentage' => $financedPercentage,
            'financial_commission_total' => $financialCommissionTotal,
            'financial_discount_total' => $financialDiscountTotal,
            'net_commission' => $netCommission,
            'financed_incentive' => $financedIncentive,
            'block_1_commission' => $block1Commission,
            'valid_financial_benefit' => $validFinancialBenefit,
            'profitability_percentage' => $profitabilityPercentage,
            'profitability_incentive' => $profitabilityIncentive,
            'block_2_commission' => $block2Commission,
            'premium_guarantee_total' => $premiumGuaranteeTotal,
            'guarantee_percentage' => $guaranteePercentage,
            'guarantee_incentive' => $guaranteeIncentive,
            'block_3_commission' => $block3Commission,
            'final_commission' => $finalCommission,
        ];
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

        if ($zone === '') {
            return 'Sin Zona';
        }

        $lower = Str::of($zone)->lower()->trim()->toString();

        return match ($lower) {
            'zona cristina' => 'Zona Cristina',
            'zona nuria' => 'Zona Nuria',
            'zona carlos' => 'Zona Carlos',
            'zona irene' => 'Zona Irene',
            'general', 'sin zona' => 'Sin Zona',
            default => $zone,
        };
    }

    private function fallbackZoneFromDelegation(?string $delegation): string
    {
        $label = $this->formulaConfig->normalizeDelegationLabel($delegation);
        $key = Str::of($label)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        return self::ZONE_BY_DELEGATION[$key] ?? 'Sin Zona';
    }

    private function isExcludedZone(string $zoneName): bool
    {
        return in_array(
            Str::of($zoneName)->lower()->trim()->toString(),
            ['sin zona', 'general'],
            true
        );
    }

    private function normalizeInterestRate(string $value): string
    {
        return Str::of($value)
            ->replace(',', '.')
            ->replace('%', '')
            ->trim()
            ->toString();
    }

    private function blockingIssues(): array
    {
        if (! Schema::hasTable('salesforce_opportunities')) {
            return ['La tabla local salesforce_opportunities no existe todavia.'];
        }

        $requiredColumns = [
            'cv_signed_date',
            'stage_name',
            'record_type_name',
            'opo_for_importe_total',
            'importe_financiado',
            'financial_commission',
            'financial_discount',
            'garantia_total',
            'interest_rate',
            'financial_zone',
            'opportunity_record_type_formula',
        ];

        $missing = collect($requiredColumns)
            ->reject(fn (string $column) => Schema::hasColumn('salesforce_opportunities', $column))
            ->values()
            ->all();

        if ($missing !== []) {
            return [
                'Faltan columnas locales para Financieros en salesforce_opportunities: '.implode(', ', $missing).'. Ejecuta migrate y resync de opportunities.',
            ];
        }

        return [];
    }
}
