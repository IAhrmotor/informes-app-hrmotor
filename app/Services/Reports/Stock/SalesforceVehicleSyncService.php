<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceVehicle;
use App\Services\Salesforce\SalesforceClient;
use Illuminate\Support\Facades\DB;

class SalesforceVehicleSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
        private readonly StockDelegationService $delegations,
    ) {}

    public function sync(): array
    {
        $soql = $this->soql();
        $records = $this->client->query($soql);
        $seenAt = now();

        DB::transaction(function () use ($records, $seenAt): void {
            SalesforceVehicle::query()->where('is_in_stock', true)->update(['is_in_stock' => false]);

            foreach ($records as $record) {
                $salesforceId = data_get($record, 'Id');
                if (blank($salesforceId)) {
                    continue;
                }

                $delegation = $this->delegations->resolveSalesforce(
                    data_get($record, 'PRO_BUS_Delegacion__c'),
                    data_get($record, 'PRO_BUS_Delegacion__r.Name'),
                );

                SalesforceVehicle::updateOrCreate(
                    ['salesforce_id' => $salesforceId],
                    [
                        'name' => data_get($record, 'Name'),
                        'sku' => data_get($record, 'StockKeepingUnit'),
                        'plate' => data_get($record, 'PRO_TEX_Matricula__c'),
                        'brand' => data_get($record, 'PRO_SEL_Marca__c'),
                        'model' => data_get($record, 'PRO_TEX_Modelo__c'),
                        'version' => data_get($record, 'PRO_TEX_Version__c'),
                        'segment' => data_get($record, 'Segmento__c'),
                        'fuel' => data_get($record, 'PRO_SEL_Combustible__c'),
                        'body' => data_get($record, 'PRO_SEL_Carroceria__c'),
                        'mileage' => $this->integer(data_get($record, 'PRO_NUM_Kilometraje__c')),
                        'state' => data_get($record, 'PRO_SEL_Estado__c'),
                        'stock_delegation_id' => $delegation?->id,
                        'salesforce_delegation_id' => data_get($record, 'PRO_BUS_Delegacion__c'),
                        'salesforce_delegation_name' => data_get($record, 'PRO_BUS_Delegacion__r.Name'),
                        'purchase_price' => data_get($record, 'PRO_DIV_Precio_de_compra__c'),
                        'sale_price' => data_get($record, 'PRO_DIV_Precio_de_venta__c'),
                        'financed_sale_price' => data_get($record, 'PRO_DIV_Precio_venta_financiado__c'),
                        'entry_date' => data_get($record, 'PRO_FEC_Fecha_entrada__c'),
                        'buyer_id' => data_get($record, 'Comprador_oportunidad__c'),
                        'buyer_name' => data_get($record, 'Comprador_oportunidad__r.Name'),
                        'purchase_source' => data_get($record, 'Procedencia_de_compra__c'),
                        'is_in_stock' => true,
                        'last_seen_stock_at' => $seenAt,
                        'raw_payload' => $record,
                    ],
                );
            }
        });

        return ['soql' => $soql, 'queried' => count($records), 'saved' => count($records)];
    }

    public function soql(): string
    {
        return <<<'SOQL'
SELECT
    Id,
    Name,
    StockKeepingUnit,
    PRO_TEX_Matricula__c,
    PRO_SEL_Marca__c,
    PRO_TEX_Modelo__c,
    PRO_TEX_Version__c,
    Segmento__c,
    PRO_SEL_Combustible__c,
    PRO_SEL_Carroceria__c,
    PRO_NUM_Kilometraje__c,
    PRO_SEL_Estado__c,
    PRO_BUS_Delegacion__c,
    PRO_BUS_Delegacion__r.Name,
    PRO_DIV_Precio_de_compra__c,
    PRO_DIV_Precio_de_venta__c,
    PRO_DIV_Precio_venta_financiado__c,
    PRO_FEC_Fecha_entrada__c,
    Comprador_oportunidad__c,
    Comprador_oportunidad__r.Name,
    Procedencia_de_compra__c
FROM Product2
WHERE PRO_SEL_Estado__c IN ('Disponible', 'Reservado', 'Bloqueado')
SOQL;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? max((int) round((float) $value), 0) : null;
    }
}
