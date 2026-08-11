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
        $this->call([
            MasterDelegationsSeeder::class,
            MasterPortalsSeeder::class,
            MasterRuleValiditiesSeeder::class,

            MasterFormSenderMappingsSeeder::class,
            MasterCallDelegationMappingsSeeder::class,
            CallAgentMappingsSeeder::class,
        ]);

        if (app()->environment(['local', 'testing'])) {
            User::factory()->create([
                'name' => 'Synthetic local user',
                'email' => 'local-user@example.test',
            ]);

            $this->call(DemoLeadsSeeder::class);
        }
    }
}
