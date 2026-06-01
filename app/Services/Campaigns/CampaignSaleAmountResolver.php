<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\Schema;

class CampaignSaleAmountResolver
{
    private const CANDIDATE_COLUMNS = [
        'opo_for_importe_total',
        'amount',
        'sale_amount',
        'opportunity_amount',
        'importe_vendido',
        'importe_venta',
        'importe_total',
        'total_venta',
        'precio_venta',
        'precio',
    ];

    private const CANDIDATE_RAW_KEYS = [
        'OPO_FOR_Importe_total__c',
        'Amount',
        'SaleAmount',
        'Importe_vendido__c',
        'Importe_Vendido__c',
        'Importe_venta__c',
        'Importe_Venta__c',
        'Importe_total__c',
        'Importe_Total__c',
        'Precio_venta__c',
        'Precio_Venta__c',
    ];

    public function opportunitySelectColumns(): array
    {
        return array_values(array_unique(array_filter([
            ...$this->localColumns(),
            'raw_payload',
        ])));
    }

    public function localColumn(): ?string
    {
        foreach (self::CANDIDATE_COLUMNS as $column) {
            if (Schema::hasColumn('salesforce_opportunities', $column)) {
                return $column;
            }
        }

        return null;
    }

    public function localColumns(): array
    {
        return array_values(array_filter(
            self::CANDIDATE_COLUMNS,
            fn (string $column): bool => Schema::hasColumn('salesforce_opportunities', $column)
        ));
    }

    public function preferredColumnExists(): bool
    {
        return Schema::hasColumn('salesforce_opportunities', 'opo_for_importe_total');
    }

    public function resolve(object $opportunity): ?float
    {
        foreach ($this->localColumns() as $column) {
            if (isset($opportunity->{$column}) && is_numeric($opportunity->{$column})) {
                $amount = (float) $opportunity->{$column};

                if ($amount > 0) {
                    return $amount;
                }
            }
        }

        $payload = $opportunity->raw_payload ?? null;

        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        if (! is_array($payload)) {
            return null;
        }

        foreach (self::CANDIDATE_RAW_KEYS as $key) {
            $value = $payload[$key] ?? null;

            if (is_numeric($value) && (float) $value > 0) {
                return (float) $value;
            }
        }

        return null;
    }

    public function diagnosticMessage(): string
    {
        return 'No existe columna local opo_for_importe_total. Anadir Opportunity.OPO_FOR_Importe_total__c al sync de oportunidades.';
    }

    public function emptyAmountsMessage(): string
    {
        if ($this->preferredColumnExists()) {
            return 'La columna opo_for_importe_total existe, pero no contiene importes para las ventas atribuidas.';
        }

        return 'La columna amount existe, pero no contiene importes para las ventas atribuidas. Hay que confirmar el API name del campo Salesforce de importe vendido.';
    }
}
