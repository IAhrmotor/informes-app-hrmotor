<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceSaleSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SalesforceSaleSnapshotService
{
    public function __construct(
        private readonly StockDelegationService $delegations,
    ) {}

    public function captureNew(): int
    {
        $captured = 0;

        SalesforceOpportunity::query()
            ->where('cv_signed', true)
            ->whereIn('record_type_name', ['Venta', 'Cambio'])
            ->whereNotIn('salesforce_id', SalesforceSaleSnapshot::query()->select('opportunity_salesforce_id'))
            ->orderBy('id')
            ->chunkById(250, function (Collection $opportunities) use (&$captured): void {
                foreach ($opportunities as $opportunity) {
                    $this->capture($opportunity);
                    $captured++;
                }
            });

        return $captured;
    }

    public function capture(SalesforceOpportunity $opportunity): SalesforceSaleSnapshot
    {
        $signedDate = $opportunity->cv_signed_date
            ? CarbonImmutable::parse($opportunity->cv_signed_date)->startOfDay()
            : null;
        $entryDate = $opportunity->vehicle_entry_date
            ? CarbonImmutable::parse($opportunity->vehicle_entry_date)->startOfDay()
            : null;
        $qualityIssues = [];

        if (! $signedDate) {
            $qualityIssues[] = 'missing_signed_date';
        }
        if (blank($opportunity->delivery_store)) {
            $qualityIssues[] = 'missing_delivery_store';
        }
        if (! $entryDate) {
            $qualityIssues[] = 'missing_vehicle_entry_date';
        }
        if (blank($opportunity->vehicle_interest_id)) {
            $qualityIssues[] = 'missing_vehicle_interest';
        }
        if ($opportunity->contract_vehicle_sale_amount === null) {
            $qualityIssues[] = 'missing_sale_price';
        }
        if ($opportunity->vehicle_purchase_price === null) {
            $qualityIssues[] = 'missing_purchase_price';
        }
        $deliveryDelegation = filled($opportunity->delivery_store)
            ? $this->delegations->resolveSalesforce(null, $opportunity->delivery_store)
            : null;

        return SalesforceSaleSnapshot::query()->firstOrCreate(
            ['opportunity_salesforce_id' => $opportunity->salesforce_id],
            [
                'opportunity_name' => $opportunity->name,
                'record_type' => $opportunity->record_type_name,
                'signed_date' => $signedDate?->toDateString(),
                'delivery_store' => $opportunity->delivery_store,
                'stock_delegation_id' => $deliveryDelegation?->id,
                'vehicle_salesforce_id' => $opportunity->vehicle_interest_id,
                'vehicle_plate' => $opportunity->vehicle_plate,
                'vehicle_entry_date' => $entryDate?->toDateString(),
                'rotation_days' => $signedDate && $entryDate && $entryDate->lessThanOrEqualTo($signedDate)
                    ? (int) $entryDate->diffInDays($signedDate)
                    : null,
                'sale_price' => $opportunity->contract_vehicle_sale_amount,
                'purchase_price' => $opportunity->vehicle_purchase_price,
                'trade_in_vehicle_salesforce_id' => $opportunity->appraised_vehicle_id,
                'trade_in_vehicle_plate' => $opportunity->appraised_vehicle_plate,
                'trade_in_amount' => $opportunity->trade_in_amount,
                'sale_management' => $opportunity->gestion_de_venta,
                'management_cost' => $opportunity->management_cost,
                'logistics_cost' => $opportunity->logistics_cost,
                'transfer_cost' => $opportunity->transfer_cost,
                'warranty_amount' => $opportunity->garantia_total,
                'plan_auto_plus_amount' => $opportunity->plan_auto_plus_amount,
                'cae_amount' => $opportunity->cae_amount,
                'discount_amount' => $opportunity->opo_div_descuento,
                'financial_discount_amount' => $opportunity->financial_discount,
                'logistics_discount_amount' => $opportunity->logistics_discount,
                'total_amount' => $opportunity->opo_for_importe_total,
                'quality_issues' => $qualityIssues,
                'source_payload' => $opportunity->raw_payload,
                'captured_at' => now(),
            ],
        );
    }
}
