<?php

namespace App\Services\Reports\Calls;

use App\Services\Salesforce\SalesforceLeadFieldResolver;

class CallLeadPortalResolver
{
    public function __construct(
        private readonly CallPortalNormalizer $portalNormalizer,
        private readonly SalesforceLeadFieldResolver $fieldResolver,
    ) {}

    /** @return array{operational:array<string,mixed>,visible:array<string,mixed>,debug:array<string,mixed>} */
    public function resolve(mixed $portalesRaw, mixed $lead, ?array $existingVisible = null): array
    {
        $taskPortal = $this->portalNormalizer->normalize($portalesRaw);
        $debug = [
            'portales_raw' => $portalesRaw,
            'lead_id' => data_get($lead, 'Id') ?? data_get($lead, 'salesforce_id'),
            'source_origin_new_raw' => $this->value($lead, 'source_origin_new', 'Fuente_origen__c'),
            'legacy_value' => null,
            'legacy_source_field' => null,
            'effective_value' => null,
            'effective_source_field' => null,
            'used_fallback' => false,
            'conflict' => false,
        ];

        if ($portalesRaw === null || $taskPortal['portal'] !== CallPortalNormalizer::UNCLASSIFIED) {
            return ['operational' => $taskPortal, 'visible' => $taskPortal, 'debug' => $debug];
        }

        if ($lead === null) {
            if ($existingVisible !== null) {
                $debug['lead_unavailable_locally'] = true;

                return ['operational' => $taskPortal, 'visible' => $existingVisible, 'debug' => $debug];
            }

            return ['operational' => $taskPortal, 'visible' => $taskPortal, 'debug' => $debug];
        }

        $legacy = $this->legacyLeadValue($lead);
        $legacyNormalized = $this->portalNormalizer->normalizeLeadPortal($legacy['value']);
        $operational = $legacyNormalized['portal'] === CallPortalNormalizer::UNCLASSIFIED
            ? $taskPortal
            : array_merge($legacyNormalized, ['source' => 'lead']);
        $resolution = $this->fieldResolver->resolve(
            $this->value($lead, 'source_origin_new', 'Fuente_origen__c'),
            'Fuente_origen__c',
            $legacy['value'],
            $legacy['field'],
        );
        $visibleNormalized = $this->portalNormalizer->normalizeLeadPortal($resolution['effective_value']);
        $visible = array_merge($visibleNormalized, ['source' => 'lead']);
        $debug = array_merge($debug, [
            'legacy_value' => $legacy['value'],
            'legacy_source_field' => $legacy['field'],
            'effective_value' => $resolution['effective_value'],
            'effective_source_field' => $resolution['source_field'],
            'used_fallback' => $resolution['used_fallback'],
            'conflict' => $resolution['conflict'],
        ]);

        return ['operational' => $operational, 'visible' => $visible, 'debug' => $debug];
    }

    /** @return array{value:?string,field:string} */
    private function legacyLeadValue(mixed $lead): array
    {
        foreach ([
            ['portal_text', 'Portal_Text__c'],
            ['fuente_origen', 'LEA_SEL_Fuente_Origen__c'],
            ['fuente_nuevo', 'Fuente_Nuevo__c'],
        ] as [$local, $salesforce]) {
            $value = $this->value($lead, $local, $salesforce);
            if ($this->portalNormalizer->clean($value) !== null) {
                return ['value' => $value, 'field' => $salesforce];
            }
        }

        return ['value' => null, 'field' => 'fallback'];
    }

    private function value(mixed $lead, string $local, string $salesforce): mixed
    {
        return data_get($lead, $local) ?? data_get($lead, $salesforce);
    }
}
