<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceLead;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

class SalesforceMonthlyLeadsSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
    ) {
    }

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        $start = $this->soqlDateTime($periodStart);
        $end = $this->soqlDateTime($periodEnd);

        $records = $this->client->query(<<<SOQL
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
SOQL);

        $synced = 0;

        foreach ($records as $record) {
            if (blank(Arr::get($record, 'Id'))) {
                continue;
            }

            SalesforceLead::updateOrCreate(
                ['salesforce_id' => Arr::get($record, 'Id')],
                [
                    'name' => Arr::get($record, 'Name'),
                    'created_date' => $this->parseDateTime(Arr::get($record, 'CreatedDate')),
                    'last_activity_date' => Arr::get($record, 'LastActivityDate'),
                    'status' => Arr::get($record, 'Status'),
                    'owner_id' => Arr::get($record, 'OwnerId'),
                    'owner_name' => Arr::get($record, 'Owner.Name'),
                    'persona_que_trabajo_id' => Arr::get($record, 'Persona_que_trabaj__c'),
                    'persona_que_trabajo_name' => Arr::get($record, 'Persona_que_trabaj__r.Name'),
                    'propietario_descarte_id' => Arr::get($record, 'Propietario_cuando_se_descarto__c'),
                    'propietario_descarte_name' => Arr::get($record, 'Propietario_cuando_se_descarto__r.Name'),
                    'fecha_asignacion' => $this->parseDateTime(Arr::get($record, 'Fecha_Asignacion__c')),
                    'fuente_origen' => Arr::get($record, 'LEA_SEL_Fuente_Origen__c'),
                    'medio_origen' => Arr::get($record, 'LEA_SEL_Medio_Origen__c'),
                    'portal_text' => Arr::get($record, 'Portal_Text__c'),
                    'delegacion_encargada_text' => Arr::get($record, 'Delegacion_Encargada_Text__c'),
                    'raw_payload' => $record,
                ]
            );

            $synced++;
        }

        return $synced;
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
