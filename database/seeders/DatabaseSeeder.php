<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'thibaldo',
            'email' => 'thibaldo.hermoso@hrmotor.com',
        ]);

        $this->call([
            MasterDelegationsSeeder::class,
            MasterPortalsSeeder::class,
            MasterRuleValiditiesSeeder::class,

            MasterFormSenderMappingsSeeder::class,
            MasterCallDelegationMappingsSeeder::class,
            CallAgentMappingsSeeder::class,

            // Demo data for testing purposes only, not to be used in production
            DemoLeadsSeeder::class,
        ]);
    }
}
