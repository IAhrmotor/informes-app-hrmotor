<?php

namespace Database\Seeders;

use App\Models\MasterRuleValidity;
use Illuminate\Database\Seeder;

class MasterRuleValiditiesSeeder extends Seeder
{
    public function run(): void
    {
        MasterRuleValidity::updateOrCreate(
            [
                'portal_original' => 'Coches.net',
                'rule_name' => 'agrupaciones históricas',
            ],
            [
                'valid_from' => null,
                'valid_to' => null,
                'status' => 'historical_pending_close_date',
                'notes' => 'Regla histórica pendiente de cerrar cuando se confirme la fecha exacta del cambio a 1 mail y 1 número por delegación.',
            ]
        );

        MasterRuleValidity::updateOrCreate(
            [
                'portal_original' => 'Coches.net',
                'rule_name' => '1 mail + 1 número por delegación',
            ],
            [
                'valid_from' => null,
                'valid_to' => null,
                'status' => 'pending_activation_date',
                'notes' => 'Regla futura preparada. Falta confirmar fecha exacta de activación.',
            ]
        );
    }
}