<?php

namespace App\Services\Salesforce;

class SalesforceLeadMapper
{
    public function map(array $salesforceLead): array
    {
        return [
            'salesforce_id' => $salesforceLead['Id'] ?? null,
            'lead_created_at' => $salesforceLead['CreatedDate'] ?? null,

            'status' => $salesforceLead['Status'] ?? null,

            'owner_id' => $salesforceLead['OwnerId'] ?? null,
            'owner_name' => data_get($salesforceLead, 'Owner.Name'),
            'owner_delegation' => data_get($salesforceLead, 'Owner.Delegacion__c'),

            'medio_nuevo' => $salesforceLead['Medio_Nuevo__c'] ?? null,
            'fuente_nuevo' => $salesforceLead['Fuente_Nuevo__c'] ?? null,

            'portal' => $salesforceLead['Portal__c'] ?? null,
            'lea_sel_fuente_origen' => $salesforceLead['LEA_SEL_Fuente_Origen__c'] ?? null,
            'lea_sel_medio_origen' => $salesforceLead['LEA_SEL_Medio_Origen__c'] ?? null,

            'remitente_lead' => $salesforceLead['Remitente_Lead__c'] ?? null,

            'delegacion_encargada_text' => $salesforceLead['Delegacion_Encargada_Text__c'] ?? null,
            'delegacion_encargada_bueno' => $salesforceLead['Delegacion_Encargada_Bueno__c'] ?? null,
            'delegacion_encargada' => $salesforceLead['Delegacion_Encargada__c'] ?? null,
            'delegacion' => $salesforceLead['Delegacion__c'] ?? null,

            'assigned_at' => $salesforceLead['Assigned_At__c'] ?? null,
            'first_task_event_at' => $salesforceLead['First_Task_Event_At__c'] ?? null,
            'last_task_event_at' => $salesforceLead['Last_Task_Event_At__c'] ?? null,

            'raw_payload' => $salesforceLead,
        ];
    }
}