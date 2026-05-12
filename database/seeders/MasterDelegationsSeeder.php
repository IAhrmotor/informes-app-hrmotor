<?php

namespace Database\Seeders;

use App\Models\MasterDelegation;
use Illuminate\Database\Seeder;

class MasterDelegationsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['HR MOTOR A CORUÑA', 'A Coruña'],
            ['HR MOTOR ALCALA DE GUADAIRA', 'Sevilla'],
            ['HR MOTOR ALCOBENDAS', 'Madrid'],
            ['HR MOTOR ALCOY', 'Alicante'],
            ['HR MOTOR ALICANTE', 'Alicante'],
            ['HR MOTOR BADALONA', 'Barcelona'],
            ['HR MOTOR BILBAO', 'Bilbao'],
            ['HR MOTOR CASTELLON', 'Castellón'],
            ['HR MOTOR DOS HERMANAS', 'Sevilla'],
            ['HR MOTOR ELCHE', 'Alicante'],
            ['HR MOTOR FONTELLAS', 'Navarra'],
            ['HR MOTOR GIJON', 'Asturias'],
            ['HR MOTOR GIRONA', 'Girona'],
            ['HR MOTOR LLEIDA', 'Lleida'],
            ['HR MOTOR LLIÇÀ DE VALL', 'Barcelona'],
            ['HR MOTOR MALAGA', 'Málaga'],
            ['HR MOTOR MALAGA CENTRO', 'Málaga'],
            ['HR MOTOR MALLORCA', 'Mallorca'],
            ['HR MOTOR MANRESA', 'Barcelona'],
            ['HR MOTOR MURCIA', 'Murcia'],
            ['HR MOTOR PAMPLONA', 'Navarra'],
            ['HR MOTOR PATERNA', 'Valencia'],
            ['HR MOTOR RIVAS-VACIA MADRID', 'Madrid'],
            ['HR MOTOR SAN SEBASTIAN', 'San Sebastián'],
            ['HR MOTOR SANT BOI DE LLOBREGAT', 'Barcelona'],
            ['HR MOTOR SEDAVI', 'Valencia'],
            ['HR MOTOR SEVILLA', 'Sevilla'],
            ['HR MOTOR TORREJON', 'Madrid'],
            ['HR MOTOR VALENCIA', 'Valencia'],
            ['HR MOTOR VALLADOLID', 'Valladolid'],
            ['HR MOTOR VILLALBA', 'Madrid'],
            ['HR MOTOR VILLAREAL/ALMASSORA', 'Castellón'],
            ['HR MOTOR ZARAGOZA', 'Zaragoza'],
        ];

        foreach ($rows as [$delegationName, $commercialGroup]) {
            MasterDelegation::updateOrCreate(
                ['delegation_name' => $delegationName],
                [
                    'commercial_group' => $commercialGroup,
                    'is_active' => true,
                ]
            );
        }
    }
}