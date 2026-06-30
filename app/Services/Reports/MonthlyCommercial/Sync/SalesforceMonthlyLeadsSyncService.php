<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceLead;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

class SalesforceMonthlyLeadsSyncService
{
    public function __construct(
        private readonly SalesforceClient $client,
    ) {
    }

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        return $this->syncWithScope($periodStart, $periodEnd, false);
    }

    public function syncCampaignLeads(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        return $this->syncWithScope($periodStart, $periodEnd, true);
    }

    private function syncWithScope(CarbonInterface $periodStart, CarbonInterface $periodEnd, bool $campaignOnly): array
    {
        $soql = $this->leadSoql($periodStart, $periodEnd, true, $campaignOnly);
        $warnings = [];

        try {
            $records = $this->client->query($soql);
        } catch (RuntimeException $exception) {
            if (! $this->looksLikeMissingOptionalField($exception->getMessage())) {
                throw $exception;
            }

            $warnings[] = 'La query de Lead con campos opcionales fallo. Revisa API names de dashboard/campanas. Error: '.$exception->getMessage();
            $soql = $this->leadSoql($periodStart, $periodEnd, false, $campaignOnly);
            $records = $this->client->query($soql);
        }
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
                    'record_type_name' => data_get($record, 'RecordType.Name'),
                    'owner_id' => data_get($record, 'OwnerId'),
                    'owner_name' => data_get($record, 'Owner.Name'),
                    'appointment_setter_id' => data_get($record, 'Captador_de_cita__c'),
                    'appointment_setter_name' => data_get($record, 'Captador_de_cita__r.Name'),
                    'persona_que_trabajo_id' => data_get($record, 'Persona_que_trabaj__c'),
                    'persona_que_trabajo_name' => data_get($record, 'Persona_que_trabaj__r.Name'),
                    'propietario_descarte_id' => data_get($record, 'Propietario_cuando_se_descarto__c'),
                    'propietario_descarte_name' => data_get($record, 'Propietario_cuando_se_descarto__r.Name'),
                    'fecha_asignacion' => $this->parseDateTime(data_get($record, 'Fecha_Asignacion__c')),
                    'appointment_capture_date' => data_get($record, 'Fecha_captador__c'),
                    'appointment_call' => (bool) data_get($record, 'Cita_llamada__c', false),
                    'appointment_store' => (bool) data_get($record, 'Cita_Tienda__c', false),
                    'appointment_attended_status' => data_get($record, 'Acudi_a_la_cita__c'),
                    'store_commercial_id' => data_get($record, 'Comercial_que_atiende_en_tienda__c'),
                    'store_commercial_name' => data_get($record, 'Comercial_que_atiende_en_tienda__r.Name'),
                    'candidate_status_formula' => data_get($record, 'Estado_del_candidato_formula__c'),
                    'fuente_origen' => data_get($record, 'LEA_SEL_Fuente_Origen__c'),
                    'medio_origen' => data_get($record, 'LEA_SEL_Medio_Origen__c'),
                    'campaign_acquired' => data_get($record, 'Campa_a_Adquirida__c'),
                    'acquired_id' => data_get($record, 'Id_Adquirido__c'),
                    'content_acquired' => data_get($record, 'Contenido_Adquirido__c'),
                    'vehicle_interest' => data_get($record, 'LEA_BUS_Vehiculo_de_interes__c'),
                    'phone' => data_get($record, 'Phone'),
                    'mobile_phone' => data_get($record, 'MobilePhone'),
                    'email' => data_get($record, 'Email'),
                    'is_converted' => (bool) data_get($record, 'IsConverted', false),
                    'converted_date' => $this->parseDateTime(data_get($record, 'ConvertedDate')),
                    'converted_account_id' => data_get($record, 'ConvertedAccountId'),
                    'converted_contact_id' => data_get($record, 'ConvertedContactId'),
                    'converted_opportunity_id' => data_get($record, 'ConvertedOpportunityId'),
                    'medio_nuevo' => data_get($record, 'Medio_Nuevo__c'),
                    'fuente_nuevo' => data_get($record, 'Fuente_Nuevo__c'),
                    'remitente_lead' => data_get($record, 'Remitente_Lead__c'),
                    'portal_text' => data_get($record, 'Portal_Text__c'),
                    'delegacion_encargada_text' => data_get($record, 'Delegacion_Encargada_Text__c'),
                    'delegacion_encargada_bueno' => data_get($record, 'Delegacion_Encargada_Bueno__c'),
                    'delegacion_encargada' => data_get($record, 'Delegacion_Encargada__c'),
                    'delegacion_original' => null,
                    'raw_payload' => $record,
                ]
            );

            $saved++;
        }

        return [
            'soql' => $soql,
            'queried' => count($records),
            'saved' => $saved,
            'warnings' => $warnings,
        ];
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->leadSoql($periodStart, $periodEnd, true, false);
    }

    public function baseSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->leadSoql($periodStart, $periodEnd, false, false);
    }

    public function campaignLeadsSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->leadSoql($periodStart, $periodEnd, true, true);
    }

    private function leadSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd, bool $includeOptionalDashboardFields, bool $campaignOnly): string
    {
        $start = $this->soqlDateTime($periodStart);
        $end = $this->soqlDateTime($periodEnd);
        $optionalFields = $includeOptionalDashboardFields
            ? <<<'SOQL'
    Medio_Nuevo__c,
    Fuente_Nuevo__c,
    Remitente_Lead__c,
    Delegacion_Encargada_Bueno__c,
    Delegacion_Encargada__c,
    Campa_a_Adquirida__c,
    Id_Adquirido__c,
    Contenido_Adquirido__c,
    LEA_BUS_Vehiculo_de_interes__c,
    Phone,
    MobilePhone,
    Email,
    IsConverted,
    ConvertedDate,
    ConvertedAccountId,
    ConvertedContactId,
    ConvertedOpportunityId,
    Captador_de_cita__c,
    Captador_de_cita__r.Name,
    Fecha_captador__c,
    Cita_llamada__c,
    Cita_Tienda__c,
    Acudi_a_la_cita__c,
    Comercial_que_atiende_en_tienda__c,
    Comercial_que_atiende_en_tienda__r.Name,
    Estado_del_candidato_formula__c,
SOQL
            : '';

        $campaignWhere = $campaignOnly
            ? <<<'SOQL'
    AND (
        Campa_a_Adquirida__c != null
        OR Id_Adquirido__c != null
        OR Contenido_Adquirido__c != null
        OR LEA_SEL_Fuente_Origen__c != null
        OR LEA_SEL_Medio_Origen__c != null
    )
SOQL
            : '';

        return <<<SOQL
SELECT
    Id,
    Name,
    CreatedDate,
    LastActivityDate,
    Status,
    RecordType.Name,
    OwnerId,
    Owner.Name,
    Persona_que_trabaj__c,
    Persona_que_trabaj__r.Name,
    Propietario_cuando_se_descarto__c,
    Propietario_cuando_se_descarto__r.Name,
    Fecha_Asignacion__c,
    LEA_SEL_Fuente_Origen__c,
    LEA_SEL_Medio_Origen__c,
{$optionalFields}
    Portal_Text__c,
    Delegacion_Encargada_Text__c
FROM Lead
WHERE
    IsDeleted = false
    AND CreatedDate >= {$start}
    AND CreatedDate < {$end}
{$campaignWhere}
SOQL;
    }

    private function looksLikeMissingOptionalField(string $message): bool
    {
        return str_contains($message, 'INVALID_FIELD')
            || str_contains($message, 'No such column')
            || str_contains($message, 'field');
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
