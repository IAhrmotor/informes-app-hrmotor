<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceReview;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CommercialCommissionDashboardService
{
    private const PURCHASE_DATE_CUTOFF = '2026-05-01';

    private const ALLOWED_PURCHASE_SOURCES = [
        'cambio',
        'compradirecta',
    ];

    private const COMMERCIAL_PROFILES = [
        'Compra/Venta',
        'Comerciales Partner Community',
    ];

    private const TECHNICAL_OWNER_IDS = [
        '0052X00000AP4U5QAL',
        '0057R00000AKkz0QAD',
        '0057R00000CQGZaQAP',
    ];

    private const TECHNICAL_OWNER_NAMES = [
        'admin adesso',
        'api user',
        'carlos torres',
        'platform integration user',
    ];

    private const OPPORTUNITY_COLUMNS = [
        'salesforce_id',
        'name',
        'amount',
        'opo_for_importe_total',
        'stage_name',
        'record_type_name',
        'owner_id',
        'owner_name',
        'owner_is_active',
        'owner_delegation',
        'delivery_store',
        'account_name',
        'shared_delivery_id',
        'shared_delivery_name',
        'garantia_total',
        'beneficio_financiacion_comercial',
        'importe_financiado',
        'gestion_de_venta',
        'opo_div_descuento',
        'vehicle_interest_id',
        'vehicle_sale_price',
        'vehicle_purchase_price',
        'vehicle_purchase_source',
        'vehicle_purchase_date',
        'vehicle_buyer_id',
        'vehicle_buyer_name',
        'vehicle_plate',
        'vehicle_entry_date',
        'vehicle_days_in_stock',
        'cv_signed',
        'cv_signed_date',
    ];

    private const REVIEW_COLUMNS = [
        'salesforce_id',
        'created_date',
        'owner_id',
        'owner_name',
        'opportunity_salesforce_id',
        'opportunity_name',
        'opportunity_owner_id',
        'opportunity_owner_name',
        'opportunity_record_type_name',
        'opportunity_cv_signed_date',
    ];

    public function __construct(
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
        private readonly CommercialCommissionDelegationReviewsService $delegationReviews,
    ) {
    }

    public function build(
        ?string $month,
        bool $includeSummaryRows = true,
        bool $includeDelegationRows = true,
        bool $includeDetails = true,
    ): array
    {
        $selectedMonth = $this->resolveMonth($month);
        $periodStart = $selectedMonth->startOfMonth();
        $periodEnd = $periodStart->addMonth();
        $formulaSettings = $this->formulaConfig->forMonth($selectedMonth);
        $blockingIssues = $this->blockingIssues();
        $diagnostics = $this->diagnostics($selectedMonth, $periodStart, $periodEnd, $blockingIssues, $formulaSettings);
        $warnings = $this->warnings($diagnostics);
        $summaryRows = $blockingIssues === [] && $includeSummaryRows
            ? $this->buildSummaryRows($periodStart, $periodEnd, $formulaSettings, $includeDetails)
            : [];
        $delegationRows = $blockingIssues === [] && $includeDelegationRows
            ? $this->buildDelegationRows($periodStart, $periodEnd, $formulaSettings)
            : [];

        return [
            'ready' => $blockingIssues === [],
            'month' => $selectedMonth->format('Y-m'),
            'month_label' => $selectedMonth->translatedFormat('F Y'),
            'issues' => $blockingIssues,
            'warnings' => $warnings,
            'diagnostics' => $diagnostics,
            'summary_rows' => $summaryRows,
            'delegation_rows' => $delegationRows,
        ];
    }

    public function finalCommissionForCommercial(string $commercialId, CarbonImmutable|string $month): ?array
    {
        $selectedMonth = $month instanceof CarbonImmutable
            ? $month->startOfMonth()
            : CarbonImmutable::createFromFormat('Y-m', (string) $month)->startOfMonth();
        $periodStart = $selectedMonth->startOfMonth();
        $periodEnd = $periodStart->addMonth();
        $blockingIssues = $this->blockingIssues();

        if ($blockingIssues !== []) {
            return null;
        }

        $formulaSettings = $this->formulaConfig->forMonth($selectedMonth);
        $row = collect($this->buildSummaryRows($periodStart, $periodEnd, $formulaSettings))
            ->firstWhere('commercial_id', $commercialId);

        if (! is_array($row)) {
            return null;
        }

        return [
            'commercial_id' => $commercialId,
            'commercial_name' => (string) ($row['commercial_name'] ?? $commercialId),
            'month' => $selectedMonth->format('Y-m'),
            'month_label' => $selectedMonth->translatedFormat('F Y'),
            'final_commission' => round((float) ($row['final_commission'] ?? 0), 2),
        ];
    }

    private function buildSummaryRows(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        array $formulaSettings,
        bool $includeDetails = true,
    ): array
    {
        $monthlyOperations = $this->monthlyOpportunities($periodStart, $periodEnd)->get();
        $deliveries = $monthlyOperations->filter(fn (SalesforceOpportunity $row) => $this->isDelivery($row));
        $operationsByOwner = $monthlyOperations->groupBy(fn (SalesforceOpportunity $row) => (string) $row->owner_id);
        $reviewsByOwner = $this->monthlyReviews($periodStart, $periodEnd)
            ->get()
            ->groupBy(fn (SalesforceReview $review) => (string) $review->opportunity_owner_id);
        $purchaseDetails = $this->resolvePurchaseCommissionDetails($deliveries, $formulaSettings);
        $purchaseDetailsByOwner = collect($purchaseDetails)->groupBy('purchase_owner_id');
        $sharedDeliveriesByCoowner = $deliveries
            ->filter(fn (SalesforceOpportunity $row) => filled($row->shared_delivery_id))
            ->groupBy(fn (SalesforceOpportunity $row) => (string) $row->shared_delivery_id);
        $salesforceUsersById = $this->salesforceUsersById();

        $userNames = [];

        foreach ($monthlyOperations as $row) {
            if (filled($row->owner_id)) {
                $userNames[(string) $row->owner_id] = $row->owner_name ?: $row->owner_id;
            }

            if (filled($row->shared_delivery_id)) {
                $userNames[(string) $row->shared_delivery_id] = $row->shared_delivery_name ?: $row->shared_delivery_id;
            }
        }

        foreach ($purchaseDetails as $detail) {
            if (filled($detail['purchase_owner_id'] ?? null)) {
                $userNames[(string) $detail['purchase_owner_id']] = $detail['purchase_owner_name'] ?: $detail['purchase_owner_id'];
            }
        }

        $userIds = collect()
            ->merge($operationsByOwner->keys())
            ->merge($purchaseDetailsByOwner->keys())
            ->filter()
            ->unique()
            ->filter(function (string $userId) use ($userNames, $salesforceUsersById): bool {
                return $this->isEligibleCommercialUser(
                    $userId,
                    $userNames[$userId] ?? null,
                    $salesforceUsersById->get($userId)
                );
            })
            ->values();

        $rows = $userIds->map(function (string $userId) use (
            $operationsByOwner,
            $reviewsByOwner,
            $purchaseDetailsByOwner,
            $sharedDeliveriesByCoowner,
            $formulaSettings,
            $userNames,
            $salesforceUsersById,
            $includeDetails,
        ): array {
            /** @var Collection<int, SalesforceOpportunity> $ownerOperations */
            $ownerOperations = $operationsByOwner->get($userId, collect());
            $ownerDeliveries = $ownerOperations->filter(fn (SalesforceOpportunity $row) => $this->isDelivery($row))->values();
            $ownerSoloDeliveries = $ownerDeliveries->filter(fn (SalesforceOpportunity $row) => ! filled($row->shared_delivery_id))->values();
            $ownerPrimarySharedDeliveries = $ownerDeliveries->filter(fn (SalesforceOpportunity $row) => filled($row->shared_delivery_id))->values();
            $salesforceUser = $salesforceUsersById->get($userId);
            $ownerReviews = $reviewsByOwner->get($userId, collect())->values();
            $ownerPurchaseDetails = collect($purchaseDetailsByOwner->get($userId, collect()))->values();
            $ownerSharedDeliveries = $sharedDeliveriesByCoowner->get($userId, collect())->values();
            $ownerStockDeliveries = $ownerDeliveries
                ->filter(fn (SalesforceOpportunity $row) => $this->isStock150Delivery($row, $formulaSettings))
                ->values();

            $deliveriesCount = $ownerDeliveries->count();
            $operationsCount = $ownerOperations->count();
            $salesAmount = round(
                ($ownerSoloDeliveries->count() * (float) $formulaSettings['sales']['solo_delivery_amount'])
                + ($ownerPrimarySharedDeliveries->count() * (float) $formulaSettings['sales']['shared_owner_delivery_amount']),
                2
            );
            $purchasesAmount = round((float) $ownerPurchaseDetails->sum('commission_amount'), 2);
            $sharedCount = $ownerSharedDeliveries->count();
            $sharedAmount = round($sharedCount * (float) $formulaSettings['sales']['shared_secondary_delivery_amount'], 2);
            $discountTotal = round((float) $ownerOperations->sum(
                fn (SalesforceOpportunity $row) => max(0, (float) ($row->opo_div_descuento ?? 0))
            ), 2);
            $discountPenaltyAmount = round($discountTotal * 0.05, 2);
            $stock150Count = $ownerStockDeliveries->count();
            $stock150Amount = round($stock150Count * (float) $formulaSettings['stock']['amount'], 2);
            $bonus15Amount = round(
                max($deliveriesCount - (int) $formulaSettings['bonus']['start_after_delivery'], 0)
                * (float) $formulaSettings['bonus']['amount_per_delivery'],
                2
            );

            $primaTotal = round(
                $salesAmount
                + $purchasesAmount
                + $sharedAmount
                - $discountPenaltyAmount
                + $stock150Amount
                + $bonus15Amount,
                2
            );

            [$deliveryBracketLabel, $deliveryBracketPercent] = $this->deliveryBracket(
                $deliveriesCount,
                $salesforceUser?->profile_name,
                $formulaSettings
            );
            $primaAdjusted = round($primaTotal * $deliveryBracketPercent, 2);

            $guaranteeTotal = round((float) $ownerOperations->sum(
                fn (SalesforceOpportunity $row) => max(0, (float) ($row->garantia_total ?? 0))
            ), 2);
            $guaranteePenalty = $guaranteeTotal < (float) $formulaSettings['penalties']['guarantee_total_threshold'] && $primaAdjusted > 0
                ? round($primaAdjusted * (float) $formulaSettings['penalties']['guarantee_percent'], 2)
                : 0.0;

            $reviewsCount = $ownerReviews->count();
            $reviewsPercentage = $operationsCount > 0
                ? round(($reviewsCount / $operationsCount) * 100, 2)
                : 0.0;
            $reviewsPenalty = $this->reviewsPenalty($primaAdjusted, $reviewsPercentage, $formulaSettings);

            $financedAmount = round((float) $ownerOperations->sum(
                fn (SalesforceOpportunity $row) => max(0, (float) ($row->importe_financiado ?? 0))
            ), 2);
            $totalVehicleAmount = round((float) $ownerOperations->sum(function (SalesforceOpportunity $row): float {
                $amount = (float) ($row->opo_for_importe_total ?? 0);

                if ($amount <= 0) {
                    $amount = (float) ($row->amount ?? 0);
                }

                return max(0, $amount);
            }), 2);
            $financingPercentage = $totalVehicleAmount > 0
                ? round(($financedAmount / $totalVehicleAmount) * 100, 2)
                : 0.0;
            $financingPenalty = $primaAdjusted > 0
                && $totalVehicleAmount > 0
                && $financingPercentage < (float) $formulaSettings['penalties']['financing_percentage_threshold']
                ? round($primaAdjusted * (float) $formulaSettings['penalties']['financing_percent'], 2)
                : 0.0;

            $totalPenalties = round($guaranteePenalty + $reviewsPenalty + $financingPenalty, 2);
            $primaAfterPenalties = round(max($primaAdjusted - $totalPenalties, 0), 2);

            $financingBenefitTotal = round((float) $ownerOperations->sum(
                fn (SalesforceOpportunity $row) => max(0, (float) ($row->beneficio_financiacion_comercial ?? 0))
            ), 2);
            $financingProductPercent = $this->financingProductPercent($financingBenefitTotal, $formulaSettings);
            $financingProductAmount = round($financingBenefitTotal * $financingProductPercent, 2);

            $guaranteeProductPercent = $this->guaranteeProductPercent($guaranteeTotal, $formulaSettings);
            $guaranteeProductAmount = round($guaranteeTotal * $guaranteeProductPercent, 2);

            $finalCommission = round($primaAfterPenalties + $financingProductAmount + $guaranteeProductAmount, 2);

            $row = [
                'commercial_id' => $userId,
                'commercial_name' => $userNames[$userId] ?? $userId,
                'deliveries_count' => $deliveriesCount,
                'operations_count' => $operationsCount,
                'sales_amount' => $salesAmount,
                'purchases_amount' => $purchasesAmount,
                'shared_count' => $sharedCount,
                'shared_amount' => $sharedAmount,
                'discount_total' => $discountTotal,
                'discount_penalty_amount' => $discountPenaltyAmount,
                'stock_150_count' => $stock150Count,
                'stock_150_amount' => $stock150Amount,
                'bonus_15_amount' => $bonus15Amount,
                'prima_total' => $primaTotal,
                'delivery_bracket_label' => $deliveryBracketLabel,
                'delivery_bracket_percent' => round($deliveryBracketPercent * 100, 2),
                'prima_adjusted' => $primaAdjusted,
                'guarantee_total' => $guaranteeTotal,
                'guarantee_penalty' => $guaranteePenalty,
                'reviews_count' => $reviewsCount,
                'reviews_percentage' => $reviewsPercentage,
                'reviews_penalty' => $reviewsPenalty,
                'financed_amount' => $financedAmount,
                'total_vehicle_amount' => $totalVehicleAmount,
                'financing_percentage' => $financingPercentage,
                'financing_penalty' => $financingPenalty,
                'total_penalties' => $totalPenalties,
                'prima_after_penalties' => $primaAfterPenalties,
                'financing_benefit_total' => $financingBenefitTotal,
                'financing_product_percent' => round($financingProductPercent * 100, 2),
                'financing_product_amount' => $financingProductAmount,
                'guarantee_product_percent' => round($guaranteeProductPercent * 100, 2),
                'guarantee_product_amount' => $guaranteeProductAmount,
                'final_commission' => $finalCommission,
                'details' => [
                    'deliveries' => [],
                    'purchases' => [],
                    'shared' => [],
                    'stock_150' => [],
                    'reviews' => [],
                ],
            ];

            if ($includeDetails) {
                $row['details'] = [
                    'deliveries' => $ownerDeliveries
                        ->map(fn (SalesforceOpportunity $row) => [
                            'opportunity_id' => $row->salesforce_id,
                            'opportunity_name' => $row->name,
                            'record_type_name' => $row->record_type_name,
                            'cv_signed_date' => optional($row->cv_signed_date)->toDateString(),
                            'vehicle_plate' => $row->vehicle_plate,
                            'amount' => filled($row->shared_delivery_id)
                                ? (float) $formulaSettings['sales']['shared_owner_delivery_amount']
                                : (float) $formulaSettings['sales']['solo_delivery_amount'],
                        ])->values()->all(),
                    'purchases' => $ownerPurchaseDetails->all(),
                    'shared' => $ownerSharedDeliveries
                        ->map(fn (SalesforceOpportunity $row) => [
                            'opportunity_id' => $row->salesforce_id,
                            'opportunity_name' => $row->name,
                            'owner_name' => $row->owner_name,
                            'shared_delivery_name' => $row->shared_delivery_name,
                            'cv_signed_date' => optional($row->cv_signed_date)->toDateString(),
                            'amount' => (float) $formulaSettings['sales']['shared_secondary_delivery_amount'],
                        ])->values()->all(),
                    'stock_150' => $ownerStockDeliveries
                        ->map(fn (SalesforceOpportunity $row) => [
                            'opportunity_id' => $row->salesforce_id,
                            'opportunity_name' => $row->name,
                            'vehicle_plate' => $row->vehicle_plate,
                            'vehicle_entry_date' => optional($row->vehicle_entry_date)->toDateString(),
                            'cv_signed_date' => optional($row->cv_signed_date)->toDateString(),
                            'vehicle_days_in_stock' => $this->stockDaysForOpportunity($row) ?? 0,
                            'amount' => (float) $formulaSettings['stock']['amount'],
                        ])->values()->all(),
                    'reviews' => $ownerReviews
                        ->map(fn (SalesforceReview $row) => [
                            'review_id' => $row->salesforce_id,
                            'created_date' => optional($row->created_date)->toDateTimeString(),
                            'opportunity_id' => $row->opportunity_salesforce_id,
                            'opportunity_name' => $row->opportunity_name,
                            'opportunity_owner_name' => $row->opportunity_owner_name,
                            'review_owner_name' => $row->owner_name,
                        ])->values()->all(),
                ];
            }

            return $row;
        })->sortByDesc('final_commission')->values()->all();

        return $rows;
    }

    private function buildDelegationRows(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array $formulaSettings): array
    {
        $deliveries = $this->monthlyOpportunities(
            $periodStart,
            $periodEnd,
            requireActiveOwner: false,
            applySaleManagementFilter: false
        )
            ->get()
            ->filter(fn (SalesforceOpportunity $row) => $this->isDelivery($row))
            ->filter(fn (SalesforceOpportunity $row) => $this->formulaConfig->shouldIncludeDelegationLabel(
                $this->deliveryDelegation($row)
            ));

        $deliveriesByDelegation = $deliveries->groupBy(
            fn (SalesforceOpportunity $row) => $this->delegationLabel($this->deliveryDelegation($row))
        );
        $financialOperationsByDelegation = $deliveries->groupBy(
            fn (SalesforceOpportunity $row) => $this->delegationLabel($this->financialDelegation($row))
        );
        $configuredGoals = collect($formulaSettings['delegations']['goals'] ?? []);
        $delegationLabels = collect($deliveriesByDelegation->keys())
            ->merge($configuredGoals->map(fn (array $goal, string $key) => (string) ($goal['label'] ?? $key)))
            ->filter(fn (string $label) => $this->formulaConfig->shouldIncludeDelegationLabel($label))
            ->filter(fn (string $label) => trim($label) !== '')
            ->unique()
            ->sortBy(fn (string $label) => Str::of($label)->ascii()->lower()->toString())
            ->values();
        $reviewsByDelegation = $this->delegationReviews->forMonthAndDelegations($periodStart, $delegationLabels);

        return $delegationLabels->map(function (string $delegationLabel) use ($deliveriesByDelegation, $financialOperationsByDelegation, $configuredGoals, $formulaSettings, $reviewsByDelegation): array {
            /** @var Collection<int, SalesforceOpportunity> $delegationOperations */
            $delegationOperations = $deliveriesByDelegation->get($delegationLabel, collect())->values();
            /** @var Collection<int, SalesforceOpportunity> $delegationFinancialOperations */
            $delegationFinancialOperations = $financialOperationsByDelegation->get($delegationLabel, collect())->values();
            $deliveriesCount = $delegationOperations->count();
            $goal = $this->delegationGoal($configuredGoals, $delegationLabel);
            $targetDeliveries = (int) ($goal['target_deliveries'] ?? 0);
            $objectivePercentage = $targetDeliveries > 0
                ? round(($deliveriesCount / $targetDeliveries) * 100, 2)
                : null;
            $objectiveCommissionPercent = $this->delegationObjectiveCommissionPercent($objectivePercentage, $formulaSettings);
            $rentabilityTotal = round((float) $delegationOperations->sum(
                fn (SalesforceOpportunity $row) => $this->operationRentability($row)
            ), 2);
            $averageRentability = $deliveriesCount > 0
                ? round($rentabilityTotal / $deliveriesCount, 2)
                : 0.0;
            $objectiveReached = $objectiveCommissionPercent > 0;
            $primaFinalBeforeReviews = $objectiveReached
                ? round($rentabilityTotal * $objectiveCommissionPercent, 2)
                : 0.0;
            $reviewsPayload = $reviewsByDelegation[$delegationLabel] ?? ['reviews_count' => 0, 'average_rating' => null];
            $reviewsCount = max(0, (int) ($reviewsPayload['reviews_count'] ?? 0));
            $reviewsAverageRating = is_numeric($reviewsPayload['average_rating'] ?? null)
                ? round((float) $reviewsPayload['average_rating'], 2)
                : null;
            $reviewsCoveragePercentage = $deliveriesCount > 0
                ? round(($reviewsCount / $deliveriesCount) * 100, 2)
                : 0.0;
            $reviewsCommissionAmount = $this->delegationReviewsCommissionAmount(
                $objectiveReached,
                $deliveriesCount,
                $reviewsCount,
                $reviewsAverageRating
            );
            $primaFinal = round($primaFinalBeforeReviews + $reviewsCommissionAmount, 2);
            $financingBenefitTotal = round((float) $delegationFinancialOperations->sum(
                fn (SalesforceOpportunity $row) => (float) ($row->beneficio_financiacion_comercial ?? 0)
            ), 2);
            $financedAmount = round((float) $delegationFinancialOperations->sum(
                fn (SalesforceOpportunity $row) => (float) ($row->importe_financiado ?? 0)
            ), 2);
            $totalVehicleAmount = round((float) $delegationFinancialOperations->sum(function (SalesforceOpportunity $row): float {
                $amount = (float) ($row->opo_for_importe_total ?? 0);

                if ($amount <= 0) {
                    $amount = (float) ($row->amount ?? 0);
                }

                return max(0, $amount);
            }), 2);
            $profitabilityRatio = $financedAmount > 0
                ? round(($financingBenefitTotal / $financedAmount) * 100, 2)
                : 0.0;
            $financedAmountRatio = $totalVehicleAmount > 0
                ? round(($financedAmount / $totalVehicleAmount) * 100, 2)
                : 0.0;
            $financedAmountBonusPercent = $financedAmountRatio > (float) ($formulaSettings['delegation_bonus']['financed_amount_ratio_threshold'] ?? 40.0)
                ? (float) ($formulaSettings['delegation_bonus']['financed_amount_bonus_percent'] ?? 0.10)
                : 0.0;
            $financedAmountBonusAmount = round($primaFinal * $financedAmountBonusPercent, 2);
            $baseAfterFinancedAmountBonus = $primaFinal + $financedAmountBonusAmount;
            $profitabilityBonusPercent = $profitabilityRatio > (float) ($formulaSettings['delegation_bonus']['profitability_ratio_threshold'] ?? 14.0)
                ? (float) ($formulaSettings['delegation_bonus']['profitability_bonus_percent'] ?? 0.10)
                : 0.0;
            $profitabilityBonusAmount = round($baseAfterFinancedAmountBonus * $profitabilityBonusPercent, 2);
            $totalCommission = round($baseAfterFinancedAmountBonus + $profitabilityBonusAmount, 2);

            return [
                'delegation_name' => $delegationLabel,
                'target_deliveries' => $targetDeliveries,
                'deliveries_count' => $deliveriesCount,
                'objective_percentage' => $objectivePercentage,
                'objective_reached' => $objectiveReached,
                'objective_commission_percent' => round($objectiveCommissionPercent * 100, 2),
                'rentability_total' => $rentabilityTotal,
                'average_rentability' => $averageRentability,
                'prima_final_before_reviews' => $primaFinalBeforeReviews,
                'prima_final' => $primaFinal,
                'reviews_count' => $reviewsCount,
                'reviews_average_rating' => $reviewsAverageRating,
                'reviews_coverage_percentage' => $reviewsCoveragePercentage,
                'reviews_commission_amount' => $reviewsCommissionAmount,
                'financing_profitability_percentage' => $profitabilityRatio,
                'profitability_bonus_percent' => round($profitabilityBonusPercent * 100, 2),
                'profitability_bonus_amount' => $profitabilityBonusAmount,
                'financed_amount_percentage' => $financedAmountRatio,
                'financed_amount_bonus_percent' => round($financedAmountBonusPercent * 100, 2),
                'financed_amount_bonus_amount' => $financedAmountBonusAmount,
                'total_commission' => $totalCommission,
            ];
        })->sortByDesc('total_commission')->values()->all();
    }

    private function resolvePurchaseCommissionDetails(Collection $monthlyDeliveries, array $formulaSettings): array
    {
        if ($monthlyDeliveries->isEmpty()) {
            return [];
        }

        $plates = $monthlyDeliveries->pluck('vehicle_plate')->filter()->unique()->values();
        $vehicleInterestIds = $monthlyDeliveries->pluck('vehicle_interest_id')->filter()->unique()->values();
        $normalizedPlates = $monthlyDeliveries
            ->pluck('vehicle_plate')
            ->map(fn ($plate) => $this->normalizePlate($plate))
            ->filter()
            ->unique()
            ->values();

        $purchaseCandidates = ($plates->isNotEmpty() || $vehicleInterestIds->isNotEmpty() || $normalizedPlates->isNotEmpty())
            ? $this->purchaseCandidates($plates, $vehicleInterestIds, $normalizedPlates)
            : collect();
        $purchaseCandidatesByPlate = $purchaseCandidates
            ->filter(fn (SalesforceOpportunity $row) => filled($row->vehicle_plate))
            ->groupBy(fn (SalesforceOpportunity $row) => $this->normalizePlate($row->vehicle_plate));
        $purchaseCandidatesByVehicle = $purchaseCandidates
            ->filter(fn (SalesforceOpportunity $row) => filled($row->vehicle_interest_id))
            ->groupBy(fn (SalesforceOpportunity $row) => (string) $row->vehicle_interest_id);

        $details = [];

        foreach ($monthlyDeliveries as $sale) {
            $saleDate = $sale->cv_signed_date ? CarbonImmutable::parse($sale->cv_signed_date) : null;
            $plate = (string) $sale->vehicle_plate;
            $normalizedPlate = $this->normalizePlate($sale->vehicle_plate);
            $vehicleInterestId = (string) $sale->vehicle_interest_id;

            if ($saleDate === null) {
                continue;
            }

            $candidatePool = collect()
                ->merge($vehicleInterestId !== '' ? $purchaseCandidatesByVehicle->get($vehicleInterestId, collect()) : collect())
                ->merge($normalizedPlate !== '' ? $purchaseCandidatesByPlate->get($normalizedPlate, collect()) : collect())
                ->unique(fn (SalesforceOpportunity $candidate) => (string) $candidate->salesforce_id)
                ->values();

            /** @var SalesforceOpportunity|null $purchase */
            $purchase = $candidatePool
                ->filter(function (SalesforceOpportunity $candidate) use ($sale, $saleDate): bool {
                    if ((string) $candidate->salesforce_id === (string) $sale->salesforce_id) {
                        return false;
                    }

                    if (! $candidate->cv_signed_date) {
                        return false;
                    }

                    return CarbonImmutable::parse($candidate->cv_signed_date)->lessThanOrEqualTo($saleDate);
                })
                ->sortByDesc(fn (SalesforceOpportunity $candidate) => optional($candidate->cv_signed_date)?->toDateString() ?? '')
                ->first();

            if (! $purchase) {
                $purchase = null;
            }

            $purchaseOwnerId = (string) ($sale->vehicle_buyer_id ?: $purchase?->vehicle_buyer_id ?: $purchase?->owner_id ?: '');
            $purchaseOwnerName = (string) ($sale->vehicle_buyer_name ?: $purchase?->vehicle_buyer_name ?: $purchase?->owner_name ?: $purchaseOwnerId);
            $purchaseSource = (string) ($sale->vehicle_purchase_source ?: $purchase?->vehicle_purchase_source ?: '');
            $purchaseDate = $this->resolvedPurchaseDate($sale, $purchase);

            if ($purchaseOwnerId === '' && $purchaseOwnerName === '') {
                continue;
            }

            if (! $this->purchaseSourceAllowed($purchaseSource)) {
                continue;
            }

            if (! $this->purchaseDateAllowed($purchaseDate)) {
                continue;
            }

            $rentability = $this->purchaseCommissionRentability($sale, $purchase);
            $commissionAmount = round($rentability > 0 ? $rentability * (float) $formulaSettings['purchases']['commission_percent'] : 0, 2);

            $details[] = [
                'purchase_owner_id' => $purchaseOwnerId,
                'purchase_owner_name' => $purchaseOwnerName,
                'vehicle_plate' => $plate !== '' ? $plate : (string) ($purchase?->vehicle_plate ?? ''),
                'purchase_opportunity_id' => $purchase?->salesforce_id,
                'purchase_opportunity_name' => $purchase?->name ?: 'Sin oportunidad historica local',
                'purchase_record_type_name' => $purchase?->record_type_name ?: 'Product2',
                'purchase_date' => optional($purchaseDate)->toDateString(),
                'purchase_source' => $purchaseSource !== '' ? $purchaseSource : null,
                'sale_opportunity_id' => $sale->salesforce_id,
                'sale_opportunity_name' => $sale->name,
                'sale_date' => optional($sale->cv_signed_date)->toDateString(),
                'rentability_amount' => round($rentability, 2),
                'commission_amount' => $commissionAmount,
                'source' => $purchase ? 'historical_opportunity' : 'product2_sale_vehicle',
            ];
        }

        return $details;
    }

    private function monthlyOpportunities(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        bool $requireActiveOwner = true,
        bool $applySaleManagementFilter = true
    ): Builder
    {
        $query = SalesforceOpportunity::query()
            ->select(self::OPPORTUNITY_COLUMNS)
            ->where('cv_signed', true)
            ->whereDate('cv_signed_date', '>=', $periodStart->toDateString())
            ->whereDate('cv_signed_date', '<', $periodEnd->toDateString())
            ->whereRaw('LOWER(COALESCE(stage_name, \'\')) <> ?', ['cerrada perdida']);

        if ($requireActiveOwner) {
            $query->where('owner_is_active', true);
        }

        $this->applyRecordTypeFilter($query, ['venta', 'cambio', 'tasacion']);

        if ($applySaleManagementFilter) {
            $this->applySaleManagementFilter($query);
        }

        return $query;
    }

    private function monthlyReviews(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return SalesforceReview::query()
            ->select(self::REVIEW_COLUMNS)
            ->where('created_date', '>=', $periodStart->utc()->toDateTimeString())
            ->where('created_date', '<', $periodEnd->utc()->toDateTimeString());
    }

    private function blockingIssues(): array
    {
        $issues = [];

        if (! Schema::hasTable('salesforce_opportunities')) {
            $issues[] = 'La tabla local salesforce_opportunities no existe todavia.';
        } else {
            foreach ([
                'cv_signed',
                'owner_is_active',
                'cv_signed_date',
                'stage_name',
                'record_type_name',
                'owner_id',
                'owner_name',
                'owner_delegation',
                'delivery_store',
                'shared_delivery_id',
                'shared_delivery_name',
                'vehicle_interest_id',
                'vehicle_sale_price',
                'vehicle_purchase_price',
                'vehicle_purchase_source',
                'vehicle_purchase_date',
                'vehicle_buyer_id',
                'vehicle_buyer_name',
                'vehicle_days_in_stock',
                'vehicle_plate',
                'vehicle_entry_date',
                'garantia_total',
                'beneficio_financiacion_comercial',
                'importe_financiado',
                'gestion_de_venta',
                'opo_div_descuento',
            ] as $column) {
                if (! Schema::hasColumn('salesforce_opportunities', $column)) {
                    $issues[] = "Falta la columna local salesforce_opportunities.{$column}. Ejecuta las migraciones pendientes.";
                }
            }
        }

        if (! Schema::hasTable('salesforce_reviews')) {
            $issues[] = 'La tabla local salesforce_reviews no existe todavia. Ejecuta las migraciones pendientes.';
        }

        if (! Schema::hasTable('salesforce_users')) {
            $issues[] = 'La tabla local salesforce_users no existe todavia. Ejecuta las migraciones pendientes.';
        } else {
            foreach (['salesforce_id', 'name', 'is_active'] as $column) {
                if (! Schema::hasColumn('salesforce_users', $column)) {
                    $issues[] = "Falta la columna local salesforce_users.{$column}. Ejecuta las migraciones pendientes.";
                }
            }
        }

        $saleManagementField = $this->saleManagementField();

        if ($saleManagementField !== '' && Schema::hasTable('salesforce_opportunities') && ! Schema::hasColumn('salesforce_opportunities', $saleManagementField)) {
            $issues[] = 'El filtro configurado para Gestion de venta no existe aun en la tabla local salesforce_opportunities.';
        }

        return $issues;
    }

    private function warnings(array $diagnostics): array
    {
        $warnings = [];

        if ($this->saleManagementField() === '') {
            $warnings[] = 'No se esta aplicando todavia el filtro de Gestion de venta porque falta confirmar su API name exacto.';
        }

        if (
            ($diagnostics['sales_count'] ?? 0) > 0
            && ($diagnostics['sales_with_product_buyer_count'] ?? 0) === 0
            && ($diagnostics['historical_purchase_candidates_count'] ?? 0) === 0
        ) {
            $warnings[] = 'No hay comprador de Product2 ni compras historicas locales para los vehiculos vendidos del mes. Si esperas compras liquidadas, revisa el sync de opportunities y los datos del vehiculo en Salesforce.';
        }

        return $warnings;
    }

    private function diagnostics(CarbonImmutable $selectedMonth, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array $issues, array $formulaSettings): array
    {
        $base = [
            'selected_month' => $selectedMonth->format('Y-m'),
            'selected_month_label' => $selectedMonth->translatedFormat('F Y'),
            'period_start' => $periodStart->toDateString(),
            'period_end_exclusive' => $periodEnd->toDateString(),
            'opportunities_total' => 0,
            'sales_count' => 0,
            'purchases_count' => 0,
            'operations_count' => 0,
            'shared_sales_count' => 0,
            'stock_150_count' => 0,
            'reviews_count' => 0,
            'commercials_count' => 0,
            'synced_users_count' => 0,
            'sale_management_filter_applied' => $this->hasSaleManagementFilter(),
            'sold_vehicle_links_count' => 0,
            'sales_with_product_buyer_count' => 0,
            'historical_purchase_candidates_count' => 0,
            'matched_purchase_commissions_count' => 0,
        ];

        if ($issues !== []) {
            return $base;
        }

        $baseQuery = $this->monthlyOpportunities($periodStart, $periodEnd);
        $salesQuery = $this->queryWithRecordTypes(clone $baseQuery, ['venta', 'cambio']);
        $purchasesQuery = $this->queryWithRecordTypes(clone $baseQuery, ['tasacion', 'cambio']);
        $monthlyDeliveries = (clone $salesQuery)->get();
        $soldVehicleLinksCount = $monthlyDeliveries
            ->filter(fn (SalesforceOpportunity $row) => filled($row->vehicle_interest_id) || filled($row->vehicle_plate))
            ->count();
        $historicalCandidates = $this->purchaseCandidates(
            $monthlyDeliveries->pluck('vehicle_plate')->filter()->unique()->values(),
            $monthlyDeliveries->pluck('vehicle_interest_id')->filter()->unique()->values(),
            $monthlyDeliveries
                ->pluck('vehicle_plate')
                ->map(fn ($plate) => $this->normalizePlate($plate))
                ->filter()
                ->unique()
                ->values(),
        );
        $matchedPurchaseDetails = $this->resolvePurchaseCommissionDetails($monthlyDeliveries, $formulaSettings);
        $salesforceUsersById = $this->salesforceUsersById();
        $ownerNames = [];

        foreach ($monthlyDeliveries as $row) {
            if (filled($row->owner_id)) {
                $ownerNames[(string) $row->owner_id] = $row->owner_name ?: $row->owner_id;
            }
        }

        foreach ($matchedPurchaseDetails as $detail) {
            if (filled($detail['purchase_owner_id'] ?? null)) {
                $ownerNames[(string) $detail['purchase_owner_id']] = $detail['purchase_owner_name'] ?: $detail['purchase_owner_id'];
            }
        }

        $eligibleCommercialIds = collect()
            ->merge($monthlyDeliveries->pluck('owner_id'))
            ->merge(collect($matchedPurchaseDetails)->pluck('purchase_owner_id'))
            ->filter()
            ->unique()
            ->filter(function (string $userId) use ($ownerNames, $salesforceUsersById): bool {
                return $this->isEligibleCommercialUser(
                    $userId,
                    $ownerNames[$userId] ?? null,
                    $salesforceUsersById->get($userId)
                );
            });

        return [
            ...$base,
            'opportunities_total' => (clone $baseQuery)->count(),
            'sales_count' => (clone $salesQuery)->count(),
            'purchases_count' => (clone $purchasesQuery)->count(),
            'operations_count' => (clone $baseQuery)->count(),
            'shared_sales_count' => (clone $salesQuery)->whereNotNull('shared_delivery_id')->count(),
            'stock_150_count' => $monthlyDeliveries
                ->filter(fn (SalesforceOpportunity $row) => $this->isStock150Delivery($row, $formulaSettings))
                ->count(),
            'reviews_count' => $this->monthlyReviews($periodStart, $periodEnd)->count(),
            'commercials_count' => $eligibleCommercialIds->count(),
            'synced_users_count' => SalesforceUser::query()->where('is_active', true)->count(),
            'sold_vehicle_links_count' => $soldVehicleLinksCount,
            'sales_with_product_buyer_count' => (clone $salesQuery)->whereNotNull('vehicle_buyer_id')->count(),
            'historical_purchase_candidates_count' => $historicalCandidates->count(),
            'matched_purchase_commissions_count' => count($matchedPurchaseDetails),
        ];
    }

    private function applySaleManagementFilter(Builder $query): void
    {
        $field = $this->saleManagementField();

        if ($field === '' || ! Schema::hasColumn('salesforce_opportunities', $field)) {
            return;
        }

        $query->where(function (Builder $builder) use ($field): void {
            $builder
                ->whereNull($field)
                ->orWhere($field, false)
                ->orWhere($field, 0);
        });
    }

    private function isStock150Delivery(SalesforceOpportunity $row, array $formulaSettings): bool
    {
        $days = $this->stockDaysForOpportunity($row);

        return $days !== null && $days >= (int) $formulaSettings['stock']['days_threshold'];
    }

    private function stockDaysForOpportunity(SalesforceOpportunity $row): ?int
    {
        if (! $row->cv_signed_date || ! $row->vehicle_entry_date) {
            return null;
        }

        $signedDate = CarbonImmutable::parse($row->cv_signed_date)->startOfDay();
        $entryDate = CarbonImmutable::parse($row->vehicle_entry_date)->startOfDay();
        $days = $entryDate->diffInDays($signedDate, false);

        return $days < 0 ? null : $days;
    }

    private function isDelivery(SalesforceOpportunity $row): bool
    {
        return in_array($this->normalizeRecordType($row->record_type_name), ['venta', 'cambio'], true);
    }

    private function purchaseCandidates(Collection $plates, Collection $vehicleInterestIds, Collection $normalizedPlates): Collection
    {
        if ($plates->isEmpty() && $vehicleInterestIds->isEmpty() && $normalizedPlates->isEmpty()) {
            return collect();
        }

        $query = SalesforceOpportunity::query()
            ->select(self::OPPORTUNITY_COLUMNS)
            ->where('cv_signed', true)
            ->whereRaw('LOWER(COALESCE(stage_name, \'\')) <> ?', ['cerrada perdida'])
            ->whereDate('vehicle_purchase_date', '>', self::PURCHASE_DATE_CUTOFF)
            ->where(function (Builder $builder) use ($plates, $vehicleInterestIds, $normalizedPlates): void {
                $hasPreviousCondition = false;

                if ($vehicleInterestIds->isNotEmpty()) {
                    $builder->whereIn('vehicle_interest_id', $vehicleInterestIds->all());
                    $hasPreviousCondition = true;
                }

                if ($plates->isNotEmpty()) {
                    $method = $hasPreviousCondition ? 'orWhereIn' : 'whereIn';
                    $builder->{$method}('vehicle_plate', $plates->all());
                    $hasPreviousCondition = true;
                }

                if ($normalizedPlates->isNotEmpty()) {
                    $method = $hasPreviousCondition ? 'orWhereRaw' : 'whereRaw';
                    $builder->{$method}($this->normalizedVehiclePlateSql().' IN ('.$this->sqlPlaceholders($normalizedPlates->count()).')', $normalizedPlates->all());
                }
            });

        $this->applyRecordTypeFilter($query, ['tasacion', 'cambio']);

        return $query->get();
    }

    private function normalizeRecordType(?string $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->ascii()
            ->trim()
            ->toString();
    }

    private function deliveryBracket(int $deliveries, ?string $profileName = null, array $formulaSettings = []): array
    {
        if ($this->alwaysFullDeliveryBracketProfile($profileName)) {
            return ['100%', 1.0];
        }

        $brackets = $formulaSettings['delivery_brackets'] ?? [];
        $minDeliveries = 0;

        foreach ($brackets as $bracket) {
            $maxDeliveries = $bracket['max_deliveries'] ?? null;
            $percent = (float) ($bracket['percent'] ?? 0);

            if ($maxDeliveries === null || $deliveries <= (int) $maxDeliveries) {
                $label = $maxDeliveries === null
                    ? $minDeliveries.'+'
                    : $minDeliveries.'-'.$maxDeliveries;

                return [$label, $percent];
            }

            $minDeliveries = ((int) $maxDeliveries) + 1;
        }

        return ['100%', 1.0];
    }

    private function alwaysFullDeliveryBracketProfile(?string $profileName): bool
    {
        return (string) $profileName === 'Compra/Venta';
    }

    private function financingProductPercent(float $amount, array $formulaSettings): float
    {
        foreach ($formulaSettings['financing_product_brackets'] ?? [] as $bracket) {
            if ($amount >= (float) ($bracket['min_amount'] ?? 0)) {
                return (float) ($bracket['percent'] ?? 0);
            }
        }

        return 0.0;
    }

    private function guaranteeProductPercent(float $amount, array $formulaSettings): float
    {
        foreach ($formulaSettings['guarantee_product_brackets'] ?? [] as $bracket) {
            if ($amount >= (float) ($bracket['min_amount'] ?? 0)) {
                return (float) ($bracket['percent'] ?? 0);
            }
        }

        return 0.0;
    }

    private function reviewsPenalty(float $primaAdjusted, float $reviewsPercentage, array $formulaSettings): float
    {
        if ($primaAdjusted <= 0) {
            return 0.0;
        }

        if ($reviewsPercentage < (float) $formulaSettings['penalties']['reviews_low_threshold']) {
            return round($primaAdjusted * (float) $formulaSettings['penalties']['reviews_low_percent'], 2);
        }

        if ($reviewsPercentage < (float) $formulaSettings['penalties']['reviews_mid_threshold']) {
            return round($primaAdjusted * (float) $formulaSettings['penalties']['reviews_mid_percent'], 2);
        }

        return 0.0;
    }

    private function saleManagementField(): string
    {
        return trim((string) config('commercial_commissions.sale_management_field', ''));
    }

    private function hasSaleManagementFilter(): bool
    {
        $field = $this->saleManagementField();

        return $field !== '' && Schema::hasTable('salesforce_opportunities') && Schema::hasColumn('salesforce_opportunities', $field);
    }

    private function applyRecordTypeFilter(Builder $query, array $values): void
    {
        $query->where(function (Builder $builder) use ($values): void {
            foreach (array_values($values) as $index => $value) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';

                if ($value === 'tasacion') {
                    $builder->{$method}("LOWER(COALESCE(record_type_name, '')) LIKE ?", ['tas%']);
                    continue;
                }

                $builder->{$method}("LOWER(COALESCE(record_type_name, '')) = ?", [$value]);
            }
        });
    }

    private function queryWithRecordTypes(Builder $query, array $values): Builder
    {
        $this->applyRecordTypeFilter($query, $values);

        return $query;
    }

    private function salesforceUsersById(): Collection
    {
        return SalesforceUser::query()
            ->get(['salesforce_id', 'name', 'profile_name', 'is_active'])
            ->filter(fn (SalesforceUser $user) => filled($user->salesforce_id))
            ->keyBy(fn (SalesforceUser $user) => (string) $user->salesforce_id);
    }

    private function isEligibleCommercialUser(string $userId, ?string $userName, ?SalesforceUser $salesforceUser): bool
    {
        if (in_array((string) $userId, self::TECHNICAL_OWNER_IDS, true)) {
            return false;
        }

        $normalizedName = $this->normalizeUserName($userName);

        if ($normalizedName !== '' && in_array($normalizedName, self::TECHNICAL_OWNER_NAMES, true)) {
            return false;
        }

        if ($salesforceUser === null) {
            return false;
        }

        if (in_array($this->normalizeUserName($salesforceUser->name), self::TECHNICAL_OWNER_NAMES, true)) {
            return false;
        }

        return in_array((string) $salesforceUser->profile_name, self::COMMERCIAL_PROFILES, true);
    }

    private function normalizeUserName(?string $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function operationRentability(SalesforceOpportunity $row): float
    {
        $salePrice = (float) ($row->vehicle_sale_price ?? 0);
        $purchasePrice = (float) ($row->vehicle_purchase_price ?? 0);
        $discount = (float) ($row->opo_div_descuento ?? 0);
        $financingBenefit = (float) ($row->beneficio_financiacion_comercial ?? 0);
        $guaranteeTotal = (float) ($row->garantia_total ?? 0);

        return round($salePrice - $purchasePrice - $discount + $financingBenefit + $guaranteeTotal, 2);
    }

    private function purchaseCommissionRentability(SalesforceOpportunity $sale, ?SalesforceOpportunity $purchase = null): float
    {
        $rentability = $this->operationRentability(new SalesforceOpportunity([
            'vehicle_sale_price' => $sale->vehicle_sale_price ?? $purchase?->vehicle_sale_price ?? 0,
            'vehicle_purchase_price' => $sale->vehicle_purchase_price ?? $purchase?->vehicle_purchase_price ?? 0,
            'opo_div_descuento' => $sale->opo_div_descuento ?? 0,
            'beneficio_financiacion_comercial' => $sale->beneficio_financiacion_comercial ?? 0,
            'garantia_total' => $sale->garantia_total ?? 0,
        ]));

        return round(max($rentability, 0), 2);
    }

    private function purchaseSourceAllowed(?string $value): bool
    {
        return in_array($this->normalizePurchaseSource($value), self::ALLOWED_PURCHASE_SOURCES, true);
    }

    private function purchaseDateAllowed(?CarbonImmutable $value): bool
    {
        if ($value === null) {
            return false;
        }

        return $value->greaterThan(CarbonImmutable::parse(self::PURCHASE_DATE_CUTOFF)->startOfDay());
    }

    private function resolvedPurchaseDate(SalesforceOpportunity $sale, ?SalesforceOpportunity $purchase = null): ?CarbonImmutable
    {
        $value = $sale->vehicle_purchase_date ?? $purchase?->vehicle_purchase_date;

        if (! $value) {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    private function normalizePurchaseSource(?string $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->trim()
            ->toString();
    }

    private function normalizePlate(mixed $value): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]+/', '', (string) $value) ?? '');
    }

    private function normalizedVehiclePlateSql(): string
    {
        return "REPLACE(REPLACE(UPPER(COALESCE(vehicle_plate, '')), ' ', ''), '-', '')";
    }

    private function sqlPlaceholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }

    private function resolveMonth(?string $month): CarbonImmutable
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
        }

        return CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
    }

    private function delegationLabel(?string $value): string
    {
        $label = trim((string) $value);

        return $label !== '' ? $label : 'Sin delegacion';
    }

    private function deliveryDelegation(SalesforceOpportunity $row): string
    {
        return $this->formulaConfig->deliveryDelegationLabel(
            $row->delivery_store,
            $row->owner_delegation
        );
    }

    private function financialDelegation(SalesforceOpportunity $row): string
    {
        $ownerDelegation = $this->formulaConfig->normalizeDelegationLabel($row->owner_delegation);

        if ($ownerDelegation !== '') {
            return $ownerDelegation;
        }

        return $this->deliveryDelegation($row);
    }

    private function delegationGoal(Collection $configuredGoals, string $delegationLabel): array
    {
        $goalKey = $this->formulaConfig->delegationKey($delegationLabel);

        return $configuredGoals->get($goalKey, [
            'label' => $delegationLabel,
            'target_deliveries' => 0,
        ]);
    }

    private function delegationObjectiveCommissionPercent(?float $objectivePercentage, array $formulaSettings): float
    {
        if ($objectivePercentage === null) {
            return 0.0;
        }

        foreach ($formulaSettings['delegation_bonus']['objective_brackets'] ?? [] as $bracket) {
            if ($objectivePercentage >= (float) ($bracket['min_percent'] ?? 0)) {
                return (float) ($bracket['percent'] ?? 0);
            }
        }

        return 0.0;
    }

    private function delegationReviewsCommissionAmount(
        bool $objectiveReached,
        int $deliveriesCount,
        int $reviewsCount,
        ?float $averageRating
    ): float {
        if (! $objectiveReached || $deliveriesCount <= 0 || $averageRating === null) {
            return 0.0;
        }

        $coverage = ($reviewsCount / $deliveriesCount) * 100;

        if ($coverage <= 50.0) {
            return 0.0;
        }

        if ($averageRating < 3.7) {
            return -300.0;
        }

        if ($averageRating < 4.0) {
            return -200.0;
        }

        if ($averageRating > 4.5) {
            return 200.0;
        }

        return 0.0;
    }
}
