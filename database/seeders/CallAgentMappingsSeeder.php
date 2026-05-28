<?php

namespace Database\Seeders;

use App\Models\CallAgentMapping;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CallAgentMappingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->agents() as [$code, $name, $team]) {
            $key = $code !== null
                ? ['agent_code' => $code]
                : ['normalized_name' => $this->normalizeName($name)];

            CallAgentMapping::updateOrCreate(
                $key,
                [
                    'salesforce_user_id' => null,
                    'agent_code' => $code,
                    'user_name' => $name,
                    'normalized_name' => $this->normalizeName($name),
                    'team_type' => $team,
                    'active' => true,
                ]
            );
        }

        CallAgentMapping::query()
            ->whereIn('normalized_name', [
                $this->normalizeName('Mario Fernando Morales'),
                $this->normalizeName('Jaime Castro'),
                $this->normalizeName('Carlos Soria'),
            ])
            ->update(['active' => false]);
    }

    private function agents(): array
    {
        return [
            // Atención al Cliente
            ['AG4', 'Carolina Gayarre', 'customer_service'],
            ['AG13', 'Mariela Herrera', 'customer_service'],
            ['AG6', 'Jessica Perez', 'customer_service'],
            ['AG2', 'Laura Hernandez', 'customer_service'],
            ['AG9', 'Siomara Clemente', 'customer_service'],
            ['AG14', 'Susana Gomez', 'customer_service'],
            [null, 'Vanessa Sanjuan', 'customer_service'],
            [null, 'Callcenter Fontellas', 'customer_service'],
            [null, 'Miriam Gonzalez', 'customer_service'],
            [null, 'Glenis Falcon', 'customer_service'],
            [null, 'Jennifer Guzman', 'customer_service'],

            // Tasadores
            [null, 'Alexandra Garcia', 'appraiser'],
            [null, 'German Olsen', 'appraiser'],
            [null, 'Aimar Villalba', 'appraiser'],
            [null, 'Jose Maria Bailon', 'appraiser'],

            // Contact Center
            ['AG23', 'Yuleidis Garcia', 'contact_center'],
            ['AG16', 'Maria Vidal', 'contact_center'],
            [null, 'Maria Vidal Perez', 'contact_center'],
            ['AG1', 'Vanesa German', 'contact_center'],
            ['AG17', 'Jose Ignacio Palomo', 'contact_center'],
            [null, 'Jose Palomo Casas', 'contact_center'],
            [null, 'Jose Ignacio Palomo Casas', 'contact_center'],
            ['AG18', 'Nuria Larrosa', 'contact_center'],

            // Comerciales forzados
            [null, 'Carlos Melero', 'commercial'],
            [null, 'Manuel Santamargarita', 'commercial'],
            [null, 'Jorge Martin', 'commercial'],
        ];
    }

    private function normalizeName(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
