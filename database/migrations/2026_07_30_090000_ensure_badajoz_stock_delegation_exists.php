<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $keys = ['badajoz', 'hr motor badajoz'];
            $delegations = DB::table('stock_delegations')
                ->whereIn('normalized_key', $keys)
                ->orderByRaw('CASE WHEN normalized_key = ? THEN 0 ELSE 1 END', ['badajoz'])
                ->orderBy('id')
                ->get();

            if ($delegations->isEmpty()) {
                DB::table('stock_delegations')->insert([
                    'canonical_name' => 'Badajoz',
                    'normalized_key' => 'badajoz',
                    'commercial_group' => 'Independientes',
                    'zone' => 'Zona Sur y Centro',
                    'is_commercial' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            $target = $delegations->first();
            $sourceIds = $delegations
                ->pluck('id')
                ->filter(fn ($id): bool => (int) $id !== (int) $target->id)
                ->values();

            if ($sourceIds->isNotEmpty()) {
                foreach ([
                    'salesforce_vehicles',
                    'stock_daily_snapshots',
                    'salesforce_sale_snapshots',
                    'stock_availability_alerts',
                ] as $table) {
                    DB::table($table)
                        ->whereIn('stock_delegation_id', $sourceIds)
                        ->update(['stock_delegation_id' => $target->id]);
                }
            }

            $capacitySource = $delegations->first(
                fn ($delegation): bool => $delegation->capacity_total !== null,
            );
            $salesforceSource = $delegations->first(
                fn ($delegation): bool => filled($delegation->salesforce_id),
            ) ?? $delegations->first(
                fn ($delegation): bool => filled($delegation->salesforce_name),
            );

            if ($sourceIds->isNotEmpty()) {
                DB::table('stock_delegations')->whereIn('id', $sourceIds)->delete();
            }

            DB::table('stock_delegations')->where('id', $target->id)->update([
                'salesforce_id' => $salesforceSource?->salesforce_id,
                'salesforce_name' => $salesforceSource?->salesforce_name,
                'canonical_name' => 'Badajoz',
                'normalized_key' => 'badajoz',
                'commercial_group' => 'Independientes',
                'zone' => 'Zona Sur y Centro',
                'capacity_total' => $capacitySource?->capacity_total,
                'capacity_source_name' => $capacitySource?->capacity_source_name,
                'capacity_updated_at' => $capacitySource?->capacity_updated_at,
                'is_commercial' => true,
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // The delegation may already contain capacities and stock relations.
    }
};
