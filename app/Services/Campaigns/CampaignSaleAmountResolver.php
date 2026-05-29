<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\Schema;

class CampaignSaleAmountResolver
{
    private const CANDIDATE_COLUMNS = [
        'sale_amount',
        'amount',
        'opportunity_amount',
        'importe_vendido',
        'importe_venta',
        'importe_total',
        'total_venta',
        'precio_venta',
        'precio',
    ];

    private const CANDIDATE_RAW_KEYS = [
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
        return array_values(array_filter([
            $this->localColumn(),
            'raw_payload',
        ]));
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

    public function resolve(object $opportunity): ?float
    {
        $column = $this->localColumn();

        if ($column !== null && isset($opportunity->{$column}) && is_numeric($opportunity->{$column})) {
            return (float) $opportunity->{$column};
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

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    public function diagnosticMessage(): string
    {
        return 'No existe columna local de importe vendido en salesforce_opportunities. Anadir el campo Salesforce de importe vendido al sync cuando se confirme su API name.';
    }
}
