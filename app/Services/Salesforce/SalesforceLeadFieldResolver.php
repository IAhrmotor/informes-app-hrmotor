<?php

namespace App\Services\Salesforce;

class SalesforceLeadFieldResolver
{
    /**
     * @return array{
     *     effective_value:?string,
     *     source_field:?string,
     *     used_fallback:bool,
     *     conflict:bool,
     *     new_raw:mixed,
     *     legacy_raw:mixed
     * }
     */
    public function resolve(
        mixed $newValue,
        string $newField,
        mixed $legacyValue,
        string $legacyField,
    ): array {
        $new = $this->clean($newValue);
        $legacy = $this->clean($legacyValue);

        return [
            'effective_value' => $new ?? $legacy,
            'source_field' => $new !== null ? $newField : ($legacy !== null ? $legacyField : null),
            'used_fallback' => $new === null && $legacy !== null,
            'conflict' => $new !== null && $legacy !== null && $new !== $legacy,
            'new_raw' => $newValue,
            'legacy_raw' => $legacyValue,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array{value:mixed,field:string}  $legacySource
     * @param  array{value:mixed,field:string}  $legacyChannel
     * @param  array{value:mixed,field:string}  $legacyDelegation
     * @return array<string, array<string, mixed>>
     */
    public function resolveLead(
        array $record,
        array $legacySource,
        array $legacyChannel,
        array $legacyDelegation,
    ): array {
        return [
            'source' => $this->resolve(
                data_get($record, 'Fuente_origen__c'),
                'Fuente_origen__c',
                $legacySource['value'],
                $legacySource['field'],
            ),
            'channel' => $this->resolve(
                data_get($record, 'Canal__c'),
                'Canal__c',
                $legacyChannel['value'],
                $legacyChannel['field'],
            ),
            'medium' => $this->resolve(
                data_get($record, 'Medio_origen__c'),
                'Medio_origen__c',
                data_get($record, 'LEA_SEL_Medio_Origen__c'),
                'LEA_SEL_Medio_Origen__c',
            ),
            'delegation' => $this->resolve(
                data_get($record, 'Delegacion_procedencia__c'),
                'Delegacion_procedencia__c',
                $legacyDelegation['value'],
                $legacyDelegation['field'],
            ),
            'utm_campaign' => $this->resolve(
                data_get($record, 'utm_campaign__c'),
                'utm_campaign__c',
                data_get($record, 'Campa_a_Adquirida__c'),
                'Campa_a_Adquirida__c',
            ),
            'utm_id' => $this->resolve(
                data_get($record, 'utm_id__c'),
                'utm_id__c',
                data_get($record, 'Id_Adquirido__c'),
                'Id_Adquirido__c',
            ),
            'utm_source' => $this->resolve(
                data_get($record, 'utm_source__c'),
                'utm_source__c',
                data_get($record, 'Fuente_Adquirida__c'),
                'Fuente_Adquirida__c',
            ),
            'utm_medium' => $this->resolve(
                data_get($record, 'utm_medium__c'),
                'utm_medium__c',
                data_get($record, 'Medio_Adquirido__c'),
                'Medio_Adquirido__c',
            ),
            'utm_content' => $this->resolve(
                data_get($record, 'utm_content__c'),
                'utm_content__c',
                data_get($record, 'Contenido_Adquirido__c'),
                'Contenido_Adquirido__c',
            ),
        ];
    }

    private function clean(mixed $value): ?string
    {
        $clean = trim((string) $value);

        return $clean === '' ? null : $clean;
    }
}
