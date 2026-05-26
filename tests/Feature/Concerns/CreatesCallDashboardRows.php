<?php

namespace Tests\Feature\Concerns;

use App\Models\SalesforceCall;
use Illuminate\Support\Facades\DB;

trait CreatesCallDashboardRows
{
    protected function callRow(string $id, array $overrides = []): void
    {
        SalesforceCall::create(array_merge($this->defaultCallRow($id), $overrides));
    }

    protected function insertCallRows(int $count, array $overrides = []): void
    {
        $now = now();
        $rows = [];

        foreach (range(1, $count) as $index) {
            $rows[] = array_merge($this->defaultCallRow('bulk-'.$index), [
                'created_at' => $now,
                'updated_at' => $now,
            ], $overrides);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('salesforce_calls')->insert($chunk);
        }
    }

    protected function defaultCallRow(string $id): array
    {
        return [
            'salesforce_id' => $id,
            'created_date' => '2026-05-20 10:00:00',
            'owner_name' => 'Operativo',
            'owner_profile_name' => 'Standard User',
            'operational_user_name' => 'Operativo',
            'operational_team' => 'commercial',
            'owner_team' => 'commercial',
            'delegation' => 'Alcobendas',
            'zone' => 'Zona Sur y Centro',
            'call_duration_seconds' => 80,
            'adjusted_duration_seconds' => 75,
            'call_origin' => 'commercial_direct',
            'portal_resolved' => 'Comercial directo',
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'direction' => 'inbound',
        ];
    }
}
