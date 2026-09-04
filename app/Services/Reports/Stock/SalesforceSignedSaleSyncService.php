<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceSaleSnapshot;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class SalesforceSignedSaleSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $soql = $this->soql($periodStart, $periodEnd);
        $records = $this->client->query($soql);
        $saved = 0;

        foreach ($records as $record) {
            if (blank(data_get($record, 'Id'))) {
                continue;
            }
            $onlyFinanced = (bool) data_get(
                $record,
                'OPP_BUS_Vehiculo_de_interes__r.Solo_financiado__c',
                false,
            );
            $normalSalePrice = data_get(
                $record,
                'OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_venta__c',
            );
            $financedSalePrice = data_get(
                $record,
                'OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_venta_financiado__c',
            );
            $effectiveSalePrice = $onlyFinanced
                ? ($financedSalePrice ?? $normalSalePrice)
                : $normalSalePrice;

            SalesforceOpportunity::updateOrCreate(
                ['salesforce_id' => data_get($record, 'Id')],
                [
                    'name' => data_get($record, 'Name'),
                    'created_date' => $this->parseDateTime(data_get($record, 'CreatedDate')),
                    'salesforce_last_modified_at' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
                    'stage_name' => data_get($record, 'StageName'),
                    'record_type_name' => data_get($record, 'RecordType.Name'),
                    'delivery_store' => data_get($record, 'Tienda_de_entrega__c'),
                    'garantia_total' => data_get($record, 'Garant_a_Total__c'),
                    'financial_discount' => data_get($record, 'OPO_DIV_Descuento_financiera__c'),
                    'gestion_de_venta' => (bool) data_get($record, 'Gestion_de_venta__c'),
                    'management_cost' => data_get($record, 'Costes_de_gestion__c'),
                    'logistics_cost' => data_get($record, 'Costes_de_Logistica_Incluido__c'),
                    'transfer_cost' => data_get($record, 'OPO_DIV_Coste_Traslado__c'),
                    'logistics_discount' => data_get($record, 'Descuento_Logistica__c'),
                    'opo_div_descuento' => data_get($record, 'OPO_DIV_Descuento__c'),
                    'opo_for_importe_total' => data_get($record, 'OPO_FOR_Importe_total__c'),
                    'vehicle_interest_id' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__c'),
                    'appraised_vehicle_id' => data_get($record, 'OPO_BUS_Vehiculo_a_tasar__c'),
                    'appraised_vehicle_plate' => data_get($record, 'OPO_BUS_Vehiculo_a_tasar__r.PRO_TEX_Matricula__c'),
                    'trade_in_amount' => data_get($record, 'OPO_FOR_Importe_vehiculo_a_cambio__c'),
                    'contract_vehicle_sale_amount' => data_get($record, 'OPO_FOR_Importe_vehiculo_venta__c'),
                    'plan_auto_plus_amount' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.Plan_Auto_Plus__c'),
                    'cae_amount' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.CAE__c'),
                    'vehicle_sale_price' => $effectiveSalePrice,
                    'financed_vehicle_sale_price' => $financedSalePrice,
                    'vehicle_only_financed' => $onlyFinanced,
                    'vehicle_purchase_price' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_compra__c'),
                    'vehicle_plate' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Matricula__c'),
                    'vehicle_entry_date' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.PRO_FEC_Fecha_entrada__c'),
                    'vehicle_purchase_source' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.Procedencia_de_compra__c'),
                    'vehicle_buyer_id' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__c'),
                    'vehicle_buyer_name' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__r.Name'),
                    'cv_signed' => (bool) data_get($record, 'OPO_CAS_Contrato_CV_firmado__c', false),
                    'cv_signed_date' => data_get($record, 'Fecha_firma_contrato__c'),
                    'raw_payload' => $record,
                ],
            );
            $this->fillMissingSnapshotSupplements($record);
            $saved++;
        }

        return ['soql' => $soql, 'queried' => count($records), 'saved' => $saved];
    }

    private function fillMissingSnapshotSupplements(array $record): void
    {
        $snapshot = SalesforceSaleSnapshot::query()
            ->where('opportunity_salesforce_id', data_get($record, 'Id'))
            ->first();
        if (! $snapshot) {
            return;
        }

        $updates = [];
        foreach ([
            'plan_auto_plus_amount' => 'Plan_Auto_Plus__c',
            'cae_amount' => 'CAE__c',
            'vehicle_brand' => 'PRO_SEL_Marca__c',
            'vehicle_model' => 'PRO_TEX_Modelo__c',
            'vehicle_segment' => 'Segmento__c',
            'vehicle_fuel' => 'PRO_SEL_Combustible__c',
            'vehicle_body' => 'PRO_SEL_Carroceria__c',
            'vehicle_mileage' => 'PRO_NUM_Kilometraje__c',
            'vehicle_purchase_source' => 'Procedencia_de_compra__c',
        ] as $column => $field) {
            $value = data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.'.$field);
            if ($snapshot->{$column} === null && $value !== null) {
                $updates[$column] = $value;
            }
        }
        $buyerName = data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__r.Name');
        if ($snapshot->vehicle_buyer_name === null && $buyerName !== null) {
            $updates['vehicle_buyer_name'] = $buyerName;
        }
        if ($updates !== []) {
            $snapshot->update($updates);
        }
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $startDate = $periodStart->utc()->toDateString();
        $endDate = $periodEnd->utc()->toDateString();
        $startDateTime = $periodStart->utc()->format('Y-m-d\TH:i:s\Z');

        return <<<SOQL
SELECT
    Id,
    Name,
    CreatedDate,
    StageName,
    LastModifiedDate,
    RecordType.Name,
    OPO_CAS_Contrato_CV_firmado__c,
    Fecha_firma_contrato__c,
    Tienda_de_entrega__c,
    OPP_BUS_Vehiculo_de_interes__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Matricula__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_SEL_Marca__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Modelo__c,
    OPP_BUS_Vehiculo_de_interes__r.Segmento__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_SEL_Combustible__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_SEL_Carroceria__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_NUM_Kilometraje__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_venta__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_venta_financiado__c,
    OPP_BUS_Vehiculo_de_interes__r.Solo_financiado__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.Plan_Auto_Plus__c,
    OPP_BUS_Vehiculo_de_interes__r.CAE__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_FEC_Fecha_entrada__c,
    OPP_BUS_Vehiculo_de_interes__r.Procedencia_de_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__c,
    OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__r.Name,
    OPO_BUS_Vehiculo_a_tasar__c,
    OPO_BUS_Vehiculo_a_tasar__r.PRO_TEX_Matricula__c,
    OPO_FOR_Importe_vehiculo_venta__c,
    OPO_FOR_Importe_vehiculo_a_cambio__c,
    Gestion_de_venta__c,
    Costes_de_gestion__c,
    Costes_de_Logistica_Incluido__c,
    OPO_DIV_Coste_Traslado__c,
    Garant_a_Total__c,
    OPO_DIV_Descuento__c,
    OPO_DIV_Descuento_financiera__c,
    Descuento_Logistica__c,
    OPO_FOR_Importe_total__c
FROM Opportunity
WHERE IsDeleted = false
    AND RecordType.Name IN ('Venta', 'Cambio')
    AND (
        (
            OPO_CAS_Contrato_CV_firmado__c = true
            AND Fecha_firma_contrato__c >= {$startDate}
            AND Fecha_firma_contrato__c < {$endDate}
        )
        OR LastModifiedDate >= {$startDateTime}
    )
SOQL;
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value);
    }
}
