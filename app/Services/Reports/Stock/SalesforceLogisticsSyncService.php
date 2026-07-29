<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceLogistic;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonInterface;

class SalesforceLogisticsSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    public function sync(CarbonInterface $since): array
    {
        $soql = $this->soql($since);
        $records = $this->client->query($soql);
        $saved = 0;

        foreach ($records as $record) {
            if (blank(data_get($record, 'Id'))) {
                continue;
            }

            SalesforceLogistic::updateOrCreate(
                ['salesforce_id' => data_get($record, 'Id')],
                [
                    'name' => data_get($record, 'Name'),
                    'vehicle_salesforce_id' => data_get($record, 'LOG_BUS_Vehiculo_a_transportar__c'),
                    'vehicle_name' => data_get($record, 'LOG_BUS_Vehiculo_a_transportar__r.Name'),
                    'origin_delegation_salesforce_id' => data_get($record, 'LOG_BUS_Delegacion_Origen__c'),
                    'origin_delegation_name' => data_get($record, 'LOG_BUS_Delegacion_Origen__r.Name'),
                    'destination_delegation_salesforce_id' => data_get($record, 'LOG_BUS_Delegacion_Destino__c'),
                    'destination_delegation_name' => data_get($record, 'LOG_BUS_Delegacion_Destino__r.Name'),
                    'state' => data_get($record, 'LOG_SEL_Estado__c'),
                    'transport_date' => data_get($record, 'LOG_FEC_Fecha_de_transporte__c'),
                    'reception_date' => data_get($record, 'LOG_FEC_Fecha_recepcion__c'),
                    'destination_date' => data_get($record, 'Fecha_en_destino__c'),
                    'salesforce_last_modified_at' => data_get($record, 'LastModifiedDate'),
                    'raw_payload' => $record,
                ],
            );
            $saved++;
        }

        return ['soql' => $soql, 'queried' => count($records), 'saved' => $saved];
    }

    public function soql(CarbonInterface $since): string
    {
        $date = $since->utc()->format('Y-m-d\TH:i:s\Z');

        return <<<SOQL
SELECT
    Id,
    Name,
    LastModifiedDate,
    LOG_BUS_Vehiculo_a_transportar__c,
    LOG_BUS_Vehiculo_a_transportar__r.Name,
    LOG_BUS_Delegacion_Origen__c,
    LOG_BUS_Delegacion_Origen__r.Name,
    LOG_BUS_Delegacion_Destino__c,
    LOG_BUS_Delegacion_Destino__r.Name,
    LOG_SEL_Estado__c,
    LOG_FEC_Fecha_de_transporte__c,
    LOG_FEC_Fecha_recepcion__c,
    Fecha_en_destino__c
FROM Logistica__c
WHERE IsDeleted = false
    AND LastModifiedDate >= {$date}
SOQL;
    }
}
