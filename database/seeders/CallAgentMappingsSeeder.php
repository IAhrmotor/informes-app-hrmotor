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
            CallAgentMapping::updateOrCreate(
                ['agent_code' => $code],
                [
                    'salesforce_user_id' => null,
                    'user_name' => $name,
                    'normalized_name' => $this->normalizeName($name),
                    'team_type' => $team,
                    'active' => true,
                ]
            );
        }
    }

    private function agents(): array
    {
        return [
            ['AG4', 'Carolina Gayarre', 'customer_service'],
            ['AG2', 'Laura Hernandez', 'customer_service'],
            ['AG9', 'Siomara Clemente', 'customer_service'],
            ['AG14', 'Susana Gomez', 'customer_service'],
            ['AG13', 'Mariela Herrera', 'customer_service'],
            ['AG6', 'Jessica Perez', 'customer_service'],
            ['AG1', 'Vanesa German', 'contact_center'],
            ['AG15', 'Mario Fernando Morales', 'contact_center'],
            ['AG16', 'Maria Vidal', 'contact_center'],
            ['AG17', 'Jose Ignacio Palomo', 'contact_center'],
            ['AG18', 'Nuria Larrosa', 'contact_center'],
            ['AG23', 'Yuleidis Garcia', 'contact_center'],
            ['AG24', 'Jaime Castro', 'contact_center'],
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
