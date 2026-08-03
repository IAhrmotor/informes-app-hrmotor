<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Str;

class LeadPortalResolver
{
    /**
     * @return array{channel:string, portal:string, source:string}
     */
    public function resolve(mixed $lead): array
    {
        $channel = $this->channel(data_get($lead, 'medio_nuevo'));
        $candidates = $channel === 'Llamada'
            ? [
                'Fuente_Nuevo__c' => data_get($lead, 'fuente_nuevo'),
                'Portal_Text__c' => data_get($lead, 'portal_text'),
                'LEA_SEL_Fuente_Origen__c' => data_get($lead, 'fuente_origen'),
            ]
            : [
                'Portal_Text__c' => data_get($lead, 'portal_text'),
                'LEA_SEL_Fuente_Origen__c' => data_get($lead, 'fuente_origen'),
                'Fuente_Nuevo__c' => data_get($lead, 'fuente_nuevo'),
            ];

        foreach ($candidates as $source => $value) {
            $clean = $this->clean($value);

            if ($clean !== null) {
                return ['channel' => $channel, 'portal' => $clean, 'source' => $source];
            }
        }

        return ['channel' => $channel, 'portal' => 'Sin clasificar', 'source' => 'fallback'];
    }

    public function channel(mixed $medioNuevo): string
    {
        $normalized = Str::of((string) $medioNuevo)->trim()->lower()->ascii()->toString();

        return $normalized === 'llamada' ? 'Llamada' : 'Formulario';
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
