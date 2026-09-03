<?php

namespace App\Services\Reports\Leads;

use App\Services\Salesforce\SalesforceLeadFieldResolver;

/**
 * Composes the new Salesforce fields with the frozen legacy Lead rules.
 */
class LeadClassificationResolver
{
    public function __construct(
        private readonly LeadPortalResolver $legacyPortalResolver = new LeadPortalResolver,
        private readonly SalesforceLeadFieldResolver $fieldResolver = new SalesforceLeadFieldResolver,
    ) {}

    /**
     * @return array{fields:array<string, array<string, mixed>>,channel:string,portal:string,portal_resolution_source:string}
     */
    public function resolve(mixed $lead): array
    {
        $legacyPortal = $this->legacyPortalResolver->resolve([
            'medio_nuevo' => $this->value($lead, 'medio_nuevo', 'Medio_Nuevo__c'),
            'fuente_nuevo' => $this->value($lead, 'fuente_nuevo', 'Fuente_Nuevo__c'),
            'portal_text' => $this->value($lead, 'portal_text', 'Portal_Text__c'),
            'fuente_origen' => $this->value($lead, 'fuente_origen', 'LEA_SEL_Fuente_Origen__c'),
        ]);

        $legacyDelegation = $this->firstInformed($lead, [
            ['delegacion_encargada_bueno', 'Delegacion_Encargada_Bueno__c'],
            ['delegacion_encargada', 'Delegacion_Encargada__c'],
            ['delegacion_encargada_text', 'Delegacion_Encargada_Text__c'],
        ]);

        $record = [
            'Fuente_origen__c' => $this->value($lead, 'source_origin_new', 'Fuente_origen__c'),
            'Medio_origen__c' => $this->value($lead, 'medium_origin_new', 'Medio_origen__c'),
            'Canal__c' => $this->value($lead, 'channel_new', 'Canal__c'),
            'Delegacion_procedencia__c' => $this->value($lead, 'delegation_origin_new', 'Delegacion_procedencia__c'),
            'LEA_SEL_Medio_Origen__c' => $this->value($lead, 'medio_origen', 'LEA_SEL_Medio_Origen__c'),
            'utm_campaign__c' => $this->value($lead, 'utm_campaign_new', 'utm_campaign__c'),
            'utm_id__c' => $this->value($lead, 'utm_id_new', 'utm_id__c'),
            'utm_source__c' => $this->value($lead, 'utm_source_new', 'utm_source__c'),
            'utm_medium__c' => $this->value($lead, 'utm_medium_new', 'utm_medium__c'),
            'utm_content__c' => $this->value($lead, 'utm_content_new', 'utm_content__c'),
            'Campa_a_Adquirida__c' => $this->value($lead, 'campaign_acquired', 'Campa_a_Adquirida__c'),
            'Id_Adquirido__c' => $this->value($lead, 'acquired_id', 'Id_Adquirido__c'),
            'Fuente_Adquirida__c' => $this->value($lead, 'acquired_source_legacy', 'Fuente_Adquirida__c'),
            'Medio_Adquirido__c' => $this->value($lead, 'acquired_medium_legacy', 'Medio_Adquirido__c'),
            'Contenido_Adquirido__c' => $this->value($lead, 'content_acquired', 'Contenido_Adquirido__c'),
        ];
        $fields = $this->fieldResolver->resolveLead(
            $record,
            ['value' => $legacyPortal['source'] === 'fallback' ? null : $legacyPortal['portal'], 'field' => $legacyPortal['source']],
            ['value' => $legacyPortal['channel'], 'field' => 'Medio_Nuevo__c'],
            $legacyDelegation,
        );
        if ($fields['source']['effective_value'] === null) {
            $fields['source']['effective_value'] = 'Sin clasificar';
            $fields['source']['source_field'] = 'fallback';
        }

        return [
            'fields' => $fields,
            'channel' => $fields['channel']['effective_value'] ?? $legacyPortal['channel'],
            'portal' => $fields['source']['effective_value'],
            'portal_resolution_source' => $fields['source']['source_field'],
        ];
    }

    /**
     * @return array{effective_value:?string,source_field:?string,used_fallback:bool,conflict:bool,new_raw:mixed,legacy_raw:mixed}
     */
    public function resolveLegacyDelegation(mixed $lead): array
    {
        $legacyDelegation = $this->firstInformed($lead, [
            ['delegacion_encargada_bueno', 'Delegacion_Encargada_Bueno__c'],
            ['delegacion_encargada', 'Delegacion_Encargada__c'],
            ['delegacion_encargada_text', 'Delegacion_Encargada_Text__c'],
        ]);

        return $this->fieldResolver->resolve(
            null,
            'Delegacion_procedencia__c',
            $legacyDelegation['value'],
            $legacyDelegation['field'],
        );
    }

    /** @param list<array{0:string,1:string}> $fields @return array{value:mixed,field:string} */
    private function firstInformed(mixed $lead, array $fields): array
    {
        foreach ($fields as [$local, $salesforce]) {
            $value = $this->value($lead, $local, $salesforce);

            if (trim((string) $value) !== '') {
                return ['value' => $value, 'field' => $salesforce];
            }
        }

        return ['value' => null, 'field' => 'legacy_delegation_fallback'];
    }

    private function value(mixed $lead, string $local, string $salesforce): mixed
    {
        $value = data_get($lead, $local);

        return $value ?? data_get($lead, $salesforce);
    }
}
