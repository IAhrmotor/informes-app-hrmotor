<?php

namespace Database\Seeders;

use App\Models\LeadRaw;
use Illuminate\Database\Seeder;

class DemoLeadsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'salesforce_id' => 'DEMO-001',
                'lead_created_at' => now()->subDays(3),
                'status' => 'Convertido',
                'owner_name' => 'Comercial Demo Madrid',
                'medio_nuevo' => 'Llamada',
                'fuente_nuevo' => 'Coches.net',
                'delegacion_encargada_text' => 'Madrid',
                'assigned_at' => now()->subDays(3)->addMinutes(15),
                'first_task_event_at' => now()->subDays(3)->addMinutes(45),
                'last_task_event_at' => now()->subDay(),
            ],
            [
                'salesforce_id' => 'DEMO-002',
                'lead_created_at' => now()->subDays(2),
                'status' => 'Nuevo',
                'owner_name' => 'Comercial Demo Barcelona',
                'medio_nuevo' => 'Formulario',
                'portal' => 'Coches.net',
                'remitente_lead' => 'leadssantboi@hrmotor.com',
                'assigned_at' => now()->subDays(2)->addMinutes(22),
            ],
            [
                'salesforce_id' => 'DEMO-003',
                'lead_created_at' => now()->subDays(4),
                'status' => 'Descartado',
                'owner_name' => 'Comercial Demo Valencia',
                'medio_nuevo' => 'Formulario',
                'portal' => 'Wallapop',
                'remitente_lead' => 'leadsvalencia@hrmotor.com',
            ],
            [
                'salesforce_id' => 'DEMO-004',
                'lead_created_at' => now()->subDays(1),
                'status' => 'Convertido',
                'owner_name' => 'Comercial Demo Expo',
                'medio_nuevo' => 'Formulario',
                'portal' => 'Exposición',
                'delegacion' => 'HR MOTOR ZARAGOZA',
                'owner_delegation' => 'HR MOTOR ZARAGOZA',
                'first_task_event_at' => now()->subDay()->addHour(),
            ],
            [
                'salesforce_id' => 'DEMO-005',
                'lead_created_at' => now()->subDays(5),
                'status' => 'Nuevo',
                'owner_name' => null,
                'medio_nuevo' => 'Formulario',
                'portal' => 'Exposición',
                'remitente_lead' => null,
                'delegacion' => null,
                'owner_delegation' => null,
            ],
            [
                'salesforce_id' => 'DEMO-006',
                'lead_created_at' => now()->subDays(6),
                'status' => 'Nuevo',
                'owner_name' => 'Comercial Demo Web',
                'medio_nuevo' => 'Formulario',
                'portal' => 'Web',
                'remitente_lead' => null,
                'delegacion_encargada_bueno' => 'HR MOTOR ALICANTE',
            ],
            [
                'salesforce_id' => 'DEMO-007',
                'lead_created_at' => now()->subDays(7),
                'status' => 'Nuevo',
                'owner_name' => 'Comercial Demo Error',
                'medio_nuevo' => 'Llamada',
                'fuente_nuevo' => 'Coches.net',
                'delegacion_encargada_text' => null,
            ],
            [
                'salesforce_id' => 'DEMO-008',
                'lead_created_at' => now()->subDays(8),
                'status' => 'Nuevo',
                'owner_name' => 'Comercial Demo Desconocido',
                'medio_nuevo' => 'Formulario',
                'portal' => 'Portal inventado',
                'remitente_lead' => 'test@hrmotor.com',
            ],
            [
                'salesforce_id' => 'DEMO-009',
                'lead_created_at' => now()->subDays(2),
                'status' => 'Convertido',
                'owner_name' => 'Comercial Demo Coches.com',
                'medio_nuevo' => 'Formulario',
                'portal' => 'Coches.com',
                'remitente_lead' => 'leadsmadrid@hrmotor.com',
            ],
            [
                'salesforce_id' => 'DEMO-010',
                'lead_created_at' => now()->subDays(2),
                'status' => 'Nuevo',
                'owner_name' => 'Comercial Demo Google Maps',
                'medio_nuevo' => 'Llamada',
                'fuente_nuevo' => 'Google Maps',
                'delegacion_encargada_text' => 'Sant Boi',
            ],
        ];

        foreach ($rows as $row) {
            LeadRaw::updateOrCreate(
                ['salesforce_id' => $row['salesforce_id']],
                $row
            );
        }
    }
}