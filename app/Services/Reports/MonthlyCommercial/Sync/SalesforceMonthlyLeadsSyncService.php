<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceLead;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class SalesforceMonthlyLeadsSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
    ) {
    }

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $soql = $this->soql($periodStart, $periodEnd);
        $records = $this->client->query($soql);
        $saved = 0;

        foreach ($records as $record) {
            if (blank(data_get($record, 'Id'))) {
                continue;
            }

            SalesforceLead::updateOrCreate(
                ['salesforce_id' => data_get($record, 'Id')],
                [
                    'name' => data_get($record, 'Name'),
                    'created_date' => $this->parseDateTime(data_get($record, 'CreatedDate')),
                    'last_activity_date' => data_get($record, 'LastActivityDate'),
                    'status' => data_get($record, 'Status'),
                    'owner_id' => data_get($record, 'OwnerId'),
                    'owner_name' => data_get($record, 'Owner.Name'),
                    'persona_que_trabajo_id' => data_get($record, 'Persona_que_trabaj__c'),
                    'persona_que_trabajo_name' => data_get($record, 'Persona_que_trabaj__r.Name'),
                    'propietario_descarte_id' => data_get($record, 'Propietario_cuando_se_descarto__c'),
                    'propietario_descarte_name' => data_get($record, 'Propietario_cuando_se_descarto__r.Name'),
                    'fecha_asignacion' => $this->parseDateTime(data_get($record, 'Fecha_Asignacion__c')),
                    'fuente_origen' => data_get($record, 'LEA_SEL_Fuente_Origen__c'),
                    'medio_origen' => data_get($record, 'LEA_SEL_Medio_Origen__c'),
                    'portal_text' => data_get($record, 'Portal_Text__c'),
                    'delegacion_encargada_text' => data_get($record, 'Delegacion_Encargada_Text__c'),
                    'raw_payload' => $record,
                ]
            );

            $saved++;
        }

        return [
            'soql' => $soql,
            'queried' => count($records),
            'saved' => $saved,
        ];
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $start = $this->soqlDateTime($periodStart);
        $end = $this->soqlDateTime($periodEnd);

        return <<<SOQL
SELECT
    Id,
    Name,
    CreatedDate,
    LastActivityDate,
    Status,
    OwnerId,
    Owner.Name,
    Persona_que_trabaj__c,
    Persona_que_trabaj__r.Name,
    Propietario_cuando_se_descarto__c,
    Propietario_cuando_se_descarto__r.Name,
    Fecha_Asignacion__c,
    LEA_SEL_Fuente_Origen__c,
    LEA_SEL_Medio_Origen__c,
    Portal_Text__c,
    Delegacion_Encargada_Text__c
FROM Lead
WHERE
    IsDeleted = false
    AND CreatedDate >= {$start}
    AND CreatedDate < {$end}
SOQL;
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
