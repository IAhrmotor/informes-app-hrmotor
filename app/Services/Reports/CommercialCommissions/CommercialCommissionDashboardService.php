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
    private const SUPPORTED_PURCHASE_RENTABILITY_FIELDS = [
        'informe_rentabilidad',
        'rentabilidad_financiera',
    ];

    public function build(?string $month): array
    {
        $selectedMonth = $this->resolveMonth($month);
        $periodStart = $selectedMonth->startOfMonth();
        $periodEnd = $periodStart->addMonth();
        $blockingIssues = $this->blockingIssues();
        $diagnostics = $this->diagnostics($selectedMonth, $periodStart, $periodEnd, $blockingIssues);
        $warnings = $this->warnings($diagnostics);
        $summaryRows = $blockingIssues === []
            ? $this->buildSummaryRows($periodStart, $periodEnd)
            : [];

        return [
            'ready' => $blockingIssues === [],
            'month' => $selectedMonth->format('Y-m'),
            'month_label' => $selectedMonth->translatedFormat('F Y'),
            'issues' => $blockingIssues,
            'warnings' => $warnings,
            'diagnostics' => $diagnostics,
            'summary_rows' => $summaryRows,
        ];
    }

    private function buildSummaryRows(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $monthlyOperations = $this->monthlyOpportunities($periodStart, $periodEnd)->get();
        $deliveries = $monthlyOperations->filter(fn (SalesforceOpportunity $row) => $this->isDelivery($row));
        $operationsByOwner = $monthlyOperations->groupBy(fn (SalesforceOpportunity $row) => (string) $row->owner_id);
        $reviewsByOwner = $this->monthlyReviews($periodStart, $periodEnd)
            ->get()
            ->groupBy(fn (SalesforceReview $review) => (string) $review->opportunity_owner_id);
        $purchaseDetails = $this->resolvePurchaseCommissionDetails($deliveries);
        $purchaseDetailsByOwner = collect($purchaseDetails)->groupBy('purchase_owner_id');
        $sharedDeliveriesByCoowner = $deliveries
            ->filter(fn (SalesforceOpportunity $row) => filled($row->shared_delivery_id))
            ->groupBy(fn (SalesforceOpportunity $row) => (string) $row->shared_delivery_id);

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

        SalesforceUser::query()
            ->where('is_active', true)
            ->get(['salesforce_id', 'name'])
            ->each(function (SalesforceUser $user) use (&$userNames): void {
                if (! filled($user->salesforce_id)) {
                    return;
                }

                $userNames[(string) $user->salesforce_id] = $user->name ?: $user->salesforce_id;
            });

        $userIds = collect(array_keys($userNames))
            ->merge($operationsByOwner->keys())
            ->merge($purchaseDetailsByOwner->keys())
            ->merge($sharedDeliveriesByCoowner->keys())
            ->filter()
            ->unique()
            ->values();

        $rows = $userIds->map(function (string $userId) use (
            $operationsByOwner,
            $reviewsByOwner,
            $purchaseDetailsByOwner,
            $sharedDeliveriesByCoowner,
            $userNames,
        ): array {
            /** @var Collection<int, SalesforceOpportunity> $ownerOperations */
            $ownerOperations = $operationsByOwner->get($userId, collect());
            $ownerDeliveries = $ownerOperations->filter(fn (SalesforceOpportunity $row) => $this->isDelivery($row))->values();
            $ownerReviews = $reviewsByOwner->get($userId, collect())->values();
            $ownerPurchaseDetails = collect($purchaseDetailsByOwner->get($userId, collect()))->values();
            $ownerSharedDeliveries = $sharedDeliveriesByCoowner->get($userId, collect())->values();
            $ownerStockDeliveries = $ownerDeliveries
                ->filter(fn (SalesforceOpportunity $row) => (int) ($row->vehicle_days_in_stock ?? 0) >= 150)
                ->values();

            $deliveriesCount = $ownerDeliveries->count();
            $operationsCount = $ownerOperations->count();
            $salesAmount = round($deliveriesCount * 60, 2);
            $purchasesAmount = round((float) $ownerPurchaseDetails->sum('commission_amount'), 2);
            $sharedCount = $ownerSharedDeliveries->count();
            $sharedAmount = round($sharedCount * 30, 2);
            $discountTotal = round((float) $ownerOperations->sum(
                fn (SalesforceOpportunity $row) => max(0, (float) ($row->opo_div_descuento ?? 0))
            ), 2);
            $discountPenaltyAmount = round($discountTotal * 0.05, 2);
            $stock150Count = $ownerStockDeliveries->count();
            $stock150Amount = round($stock150Count * 10, 2);
            $bonus15Amount = round(max($deliveriesCount - 15, 0) * 30, 2);

            $primaTotal = round(
                $salesAmount
                + $purchasesAmount
                + $sharedAmount
                - $discountPenaltyAmount
                + $stock150Amount
                + $bonus15Amount,
                2
            );

            [$deliveryBracketLabel, $deliveryBracketPercent] = $this->deliveryBracket($deliveriesCount);
            $primaAdjusted = round($primaTotal * $deliveryBracketPercent, 2);

            $guaranteeTotal = round((float) $ownerOperations->sum(
                fn (SalesforceOpportunity $row) => max(0, (float) ($row->garantia_total ?? 0))
            ), 2);
            $guaranteePenalty = $guaranteeTotal < 3500 && $primaAdjusted > 0
                ? round($primaAdjusted * 0.10, 2)
                : 0.0;

            $reviewsCount = $ownerReviews->count();
            $reviewsPercentage = $operationsCount > 0
                ? round(($reviewsCount / $operationsCount) * 100, 2)
                : 0.0;
            $reviewsPenalty = $this->reviewsPenalty($primaAdjusted, $reviewsPercentage);

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
            $financingPenalty = $primaAdjusted > 0 && $totalVehicleAmount > 0 && $financingPercentage < 40
                ? round($primaAdjusted * 0.10, 2)
                : 0.0;

            $totalPenalties = round($guaranteePenalty + $reviewsPenalty + $financingPenalty, 2);
            $primaAfterPenalties = round(max($primaAdjusted - $totalPenalties, 0), 2);

            $financingBenefitTotal = round((float) $ownerOperations->sum(
                fn (SalesforceOpportunity $row) => max(0, (float) ($row->beneficio_financiacion_comercial ?? 0))
            ), 2);
            $financingProductPercent = $this->financingProductPercent($financingBenefitTotal);
            $financingProductAmount = round($financingBenefitTotal * $financingProductPercent, 2);

            $guaranteeProductPercent = $this->guaranteeProductPercent($guaranteeTotal);
            $guaranteeProductAmount = round($guaranteeTotal * $guaranteeProductPercent, 2);

            $finalCommission = round($primaAfterPenalties + $financingProductAmount + $guaranteeProductAmount, 2);

            return [
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
                    'deliveries' => $ownerDeliveries
                        ->map(fn (SalesforceOpportunity $row) => [
                            'opportunity_id' => $row->salesforce_id,
                            'opportunity_name' => $row->name,
                            'record_type_name' => $row->record_type_name,
                            'cv_signed_date' => optional($row->cv_signed_date)->toDateString(),
                            'vehicle_plate' => $row->vehicle_plate,
                            'amount' => 60.0,
                        ])->values()->all(),
                    'purchases' => $ownerPurchaseDetails->all(),
                    'shared' => $ownerSharedDeliveries
                        ->map(fn (SalesforceOpportunity $row) => [
                            'opportunity_id' => $row->salesforce_id,
                            'opportunity_name' => $row->name,
                            'owner_name' => $row->owner_name,
                            'shared_delivery_name' => $row->shared_delivery_name,
                            'cv_signed_date' => optional($row->cv_signed_date)->toDateString(),
                            'amount' => 30.0,
                        ])->values()->all(),
                    'stock_150' => $ownerStockDeliveries
                        ->map(fn (SalesforceOpportunity $row) => [
                            'opportunity_id' => $row->salesforce_id,
                            'opportunity_name' => $row->name,
                            'vehicle_plate' => $row->vehicle_plate,
                            'vehicle_entry_date' => optional($row->vehicle_entry_date)->toDateString(),
                            'cv_signed_date' => optional($row->cv_signed_date)->toDateString(),
                            'vehicle_days_in_stock' => (int) ($row->vehicle_days_in_stock ?? 0),
                            'amount' => 10.0,
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
                ],
            ];
        })->sortByDesc('final_commission')->values()->all();

        return $rows;
    }

    private function resolvePurchaseCommissionDetails(Collection $monthlyDeliveries): array
    {
        $plates = $monthlyDeliveries->pluck('vehicle_plate')->filter()->unique()->values();
        $vehicleInterestIds = $monthlyDeliveries->pluck('vehicle_interest_id')->filter()->unique()->values();
        $normalizedPlates = $monthlyDeliveries
            ->pluck('vehicle_plate')
            ->map(fn ($plate) => $this->normalizePlate($plate))
            ->filter()
            ->unique()
            ->values();

        if ($plates->isEmpty() && $vehicleInterestIds->isEmpty() && $normalizedPlates->isEmpty()) {
            return [];
        }

        $rentabilityField = $this->purchaseRentabilityField();
        $purchaseCandidates = $this->purchaseCandidates($plates, $vehicleInterestIds, $normalizedPlates);
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

            if ($saleDate === null || ($normalizedPlate === '' && $vehicleInterestId === '')) {
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
                continue;
            }

            $rentability = max(0, (float) ($purchase->{$rentabilityField} ?? 0));
            $commissionAmount = round($rentability > 0 ? $rentability * 0.018 : 0, 2);

            $details[] = [
                'purchase_owner_id' => $purchase->owner_id,
                'purchase_owner_name' => $purchase->owner_name,
                'vehicle_plate' => $plate !== '' ? $plate : (string) $purchase->vehicle_plate,
                'purchase_opportunity_id' => $purchase->salesforce_id,
                'purchase_opportunity_name' => $purchase->name,
                'purchase_record_type_name' => $purchase->record_type_name,
                'purchase_date' => optional($purchase->cv_signed_date)->toDateString(),
                'sale_opportunity_id' => $sale->salesforce_id,
                'sale_opportunity_name' => $sale->name,
                'sale_date' => optional($sale->cv_signed_date)->toDateString(),
                'rentability_amount' => round($rentability, $rentabilityField === 'rentabilidad_financiera' ? 6 : 2),
                'commission_amount' => $commissionAmount,
            ];
        }

        return $details;
    }

    private function monthlyOpportunities(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        $query = SalesforceOpportunity::query()
            ->where('cv_signed', true)
            ->where('owner_is_active', true)
            ->whereDate('cv_signed_date', '>=', $periodStart->toDateString())
            ->whereDate('cv_signed_date', '<', $periodEnd->toDateString())
            ->whereRaw('LOWER(COALESCE(stage_name, \'\')) <> ?', ['cerrada perdida']);

        $this->applyRecordTypeFilter($query, ['venta', 'cambio', 'tasacion']);
        $this->applySaleManagementFilter($query);

        return $query;
    }

    private function monthlyReviews(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return SalesforceReview::query()
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
                'shared_delivery_id',
                'shared_delivery_name',
                'vehicle_interest_id',
                'vehicle_days_in_stock',
                'vehicle_plate',
                'vehicle_entry_date',
                'garantia_total',
                'beneficio_financiacion_comercial',
                'importe_financiado',
                'gestion_de_venta',
                'opo_div_descuento',
                'informe_rentabilidad',
                'rentabilidad_financiera',
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

        $purchaseRentabilityField = $this->purchaseRentabilityField();

        if (! in_array($purchaseRentabilityField, self::SUPPORTED_PURCHASE_RENTABILITY_FIELDS, true)) {
            $issues[] = 'La configuracion de rentabilidad de compra no apunta a un campo soportado.';
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
            && ($diagnostics['sold_vehicle_links_count'] ?? 0) > 0
            && ($diagnostics['historical_purchase_candidates_count'] ?? 0) === 0
        ) {
            $warnings[] = 'No hay compras historicas locales para los vehiculos vendidos del mes. Si esperas compras liquidadas, sincroniza el historico de opportunities para comisiones.';
        }

        return $warnings;
    }

    private function diagnostics(CarbonImmutable $selectedMonth, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array $issues): array
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
            'historical_purchase_candidates_count' => 0,
            'matched_purchase_commissions_count' => 0,
            'candidate_rentability_fields' => [
                ['field' => 'informe_rentabilidad', 'non_null_rows' => 0, 'positive_rows' => 0, 'sum' => 0.0],
                ['field' => 'rentabilidad_financiera', 'non_null_rows' => 0, 'positive_rows' => 0, 'sum' => 0.0],
            ],
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

        return [
            ...$base,
            'opportunities_total' => (clone $baseQuery)->count(),
            'sales_count' => (clone $salesQuery)->count(),
            'purchases_count' => (clone $purchasesQuery)->count(),
            'operations_count' => (clone $baseQuery)->count(),
            'shared_sales_count' => (clone $salesQuery)->whereNotNull('shared_delivery_id')->count(),
            'stock_150_count' => (clone $salesQuery)->where('vehicle_days_in_stock', '>=', 150)->count(),
            'reviews_count' => $this->monthlyReviews($periodStart, $periodEnd)->count(),
            'commercials_count' => (clone $baseQuery)->distinct('owner_id')->count('owner_id'),
            'synced_users_count' => SalesforceUser::query()->where('is_active', true)->count(),
            'sold_vehicle_links_count' => $soldVehicleLinksCount,
            'historical_purchase_candidates_count' => $historicalCandidates->count(),
            'matched_purchase_commissions_count' => count($this->resolvePurchaseCommissionDetails($monthlyDeliveries)),
            'candidate_rentability_fields' => [
                $this->rentabilityFieldDiagnostics(clone $baseQuery, 'informe_rentabilidad', 2),
                $this->rentabilityFieldDiagnostics(clone $baseQuery, 'rentabilidad_financiera', 6),
            ],
        ];
    }

    private function rentabilityFieldDiagnostics(Builder $query, string $field, int $precision): array
    {
        return [
            'field' => $field,
            'non_null_rows' => (clone $query)->whereNotNull($field)->count(),
            'positive_rows' => (clone $query)->where($field, '>', 0)->count(),
            'sum' => round((float) ((clone $query)->sum($field) ?? 0), $precision),
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
            ->where('cv_signed', true)
            ->whereRaw('LOWER(COALESCE(stage_name, \'\')) <> ?', ['cerrada perdida'])
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

    private function deliveryBracket(int $deliveries): array
    {
        if ($deliveries <= 6) {
            return ['0-6', 0.0];
        }

        if ($deliveries <= 11) {
            return ['7-11', 0.8];
        }

        return ['12+', 1.0];
    }

    private function financingProductPercent(float $amount): float
    {
        if ($amount > 50000) {
            return 0.09;
        }

        if ($amount >= 30001) {
            return 0.08;
        }

        if ($amount >= 25001) {
            return 0.07;
        }

        if ($amount >= 17001) {
            return 0.06;
        }

        if ($amount >= 12001) {
            return 0.05;
        }

        if ($amount >= 8001) {
            return 0.04;
        }

        if ($amount >= 5001) {
            return 0.03;
        }

        return $amount > 0 ? 0.02 : 0.0;
    }

    private function guaranteeProductPercent(float $amount): float
    {
        if ($amount > 20400) {
            return 0.11;
        }

        if ($amount >= 14401) {
            return 0.09;
        }

        if ($amount >= 9601) {
            return 0.07;
        }

        if ($amount >= 5401) {
            return 0.06;
        }

        if ($amount >= 3501) {
            return 0.04;
        }

        return $amount > 0 ? 0.03 : 0.0;
    }

    private function reviewsPenalty(float $primaAdjusted, float $reviewsPercentage): float
    {
        if ($primaAdjusted <= 0) {
            return 0.0;
        }

        if ($reviewsPercentage < 30) {
            return round($primaAdjusted * 0.50, 2);
        }

        if ($reviewsPercentage < 50) {
            return round($primaAdjusted * 0.10, 2);
        }

        return 0.0;
    }

    private function purchaseRentabilityField(): string
    {
        $field = trim((string) config('commercial_commissions.purchase_rentability_field', ''));

        return $field !== '' ? $field : 'informe_rentabilidad';
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
}
