<?php

namespace App\Services\Salesforce;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SalesforceLeadCsvMapper
{
    public function map(array $row): array
    {
        $delegationText = $this->clean(Arr::get($row, 'Delegacion_Encargada_Text__c'));
        $delegationBueno = $this->clean(Arr::get($row, 'Delegacion_Encargada_Bueno__c'));
        $delegationEncargada = $this->clean(Arr::get($row, 'Delegacion_Encargada__c'));

        return [
            'salesforce_id' => $this->clean(Arr::get($row, 'Id')),
            'lead_created_at' => $this->clean(Arr::get($row, 'CreatedDate')),

            'status' => $this->clean(Arr::get($row, 'Status')),

            'owner_id' => $this->clean(Arr::get($row, 'OwnerId')),
            'owner_name' => $this->clean(Arr::get($row, 'Owner.Name')),
            'owner_delegation' => null,

            /*
             * Campos operativos para comerciales.
             * Estos serán importantes para la pestaña Comerciales y para reaprovechar lógica del PDF.
             */
            'worked_by_id' => $this->clean(Arr::get($row, 'Persona_que_trabaj__c')),
            'worked_by_name' => $this->clean(Arr::get($row, 'Persona_que_trabaj__r.Name')),

            'discarded_owner_id' => $this->clean(Arr::get($row, 'Propietario_cuando_se_descarto__c')),
            'discarded_owner_name' => $this->clean(Arr::get($row, 'Propietario_cuando_se_descarto__r.Name')),

            /*
             * Campos correctos para Dirección.
             */
            'medio_nuevo' => $this->clean(Arr::get($row, 'Medio_Nuevo__c')),
            'fuente_nuevo' => $this->clean(Arr::get($row, 'Fuente_Nuevo__c')),

            /*
             * Portal principal para reporting.
             * Portal_Text__c es el campo más útil en este CSV para el portal mostrado.
             */
            'portal' => $this->clean(Arr::get($row, 'Portal_Text__c'))
                ?: $this->clean(Arr::get($row, 'LEA_SEL_Fuente_Origen__c'))
                ?: $this->clean(Arr::get($row, 'Fuente_Nuevo__c')),

            /*
             * Valor auxiliar del portal.
             * En muchos registros, Delegacion_Encargada_Text__c contiene la delegación/grupo recibida.
             * Si es email, no lo usamos como valor portal.
             */
            'portal_value' => $this->looksLikeEmail($delegationText) ? null : $delegationText,

            'lea_sel_fuente_origen' => $this->clean(Arr::get($row, 'LEA_SEL_Fuente_Origen__c')),
            'lea_sel_medio_origen' => $this->clean(Arr::get($row, 'LEA_SEL_Medio_Origen__c')),

            'remitente_lead' => $this->cleanEmail(Arr::get($row, 'Remitente_Lead__c')),

            'delegacion_encargada_text' => $delegationText,
            'delegacion_encargada_bueno' => $delegationBueno,
            'delegacion_encargada' => $delegationEncargada,
            'delegacion' => null,

            'assigned_at' => $this->clean(Arr::get($row, 'Fecha_Asignacion__c')),

            /*
             * Este CSV trae LastActivityDate pero no una fecha explícita de primera Task/Event.
             * Cuando importemos desde API o ampliemos export, lo mejor será traer First_Task/Event real.
             */
            'first_task_event_at' => null,
            'last_task_event_at' => $this->clean(Arr::get($row, 'LastActivityDate')),

            'raw_payload' => $row,
        ];
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function cleanEmail(mixed $value): ?string
    {
        $value = $this->clean($value);

        return $value !== null ? mb_strtolower($value) : null;
    }

    private function looksLikeEmail(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return Str::contains($value, '@') && filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}