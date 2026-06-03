<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CampaignTypeMappingsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach ([
            ['platform' => 'google_ads', 'campaign_name' => 'TASADOR LANDING SEARCH 1'],
            ['platform' => 'meta', 'campaign_name' => 'Expiey_Leads_Geo_Tasación'],
            ['platform' => 'meta', 'campaign_name' => 'Expiey_Leads_Geo_Tasación_Nuevas Ubicaciones'],
            ['platform' => 'meta', 'campaign_name' => 'Expiey_Leads_Geo_Tasacion'],
            ['platform' => 'meta', 'campaign_name' => 'Expiey_Leads_Geo_Tasacion_Nuevas Ubicaciones'],
        ] as $row) {
            DB::table('campaign_type_mappings')->updateOrInsert(
                [
                    'platform' => $row['platform'],
                    'campaign_id' => null,
                    'campaign_name' => $row['campaign_name'],
                ],
                [
                    'campaign_type' => 'tasacion',
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
