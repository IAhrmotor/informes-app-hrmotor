<?php

namespace Database\Seeders;

use App\Models\MasterPortal;
use Illuminate\Database\Seeder;

class MasterPortalsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['Coches.net', 'Coches.net'],
            ['1000Anuncios', '1000Anuncios'],
            ['Wallapop', 'Wallapop'],
            ['Coches.com', 'Coches.com'],
            ['Sumauto', 'Autocasion / Sumauto'],
            ['Autocasion', 'Autocasion / Sumauto'],
            ['Milanuncios', 'Milanuncios'],
            ['Web', 'Web'],
            ['Google Maps', 'Google Maps'],
            ['Meta', 'Meta'],
            ['Exposición', 'Exposición'],
        ];

        foreach ($rows as [$portalOriginal, $portalGroup]) {
            MasterPortal::updateOrCreate(
                ['portal_original' => $portalOriginal],
                [
                    'portal_group' => $portalGroup,
                    'is_active' => true,
                ]
            );
        }
    }
}