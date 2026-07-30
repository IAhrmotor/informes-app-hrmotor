<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->merge(
                ['badajoz', 'hr motor badajoz'],
                'Badajoz',
                'badajoz',
                'Independientes',
                'Zona Sur y Centro',
            );
            $this->merge(
                ['llica de vall', 'llica de valls', 'hr motor llica de vall', 'hr motor llica de valls'],
                'Lliçà de Vall',
                'llica de vall',
                'Grupo Barcelona',
                'Zona Cataluña',
            );
            $this->merge(
                ['malaga', 'malga', 'hr motor malaga'],
                'Málaga',
                'malaga',
                'Grupo Málaga',
                'Zona Sur y Centro',
            );
            $this->merge(
                ['mallorca', 'palma', 'palma de mallorca', 'hr motor palma', 'hr motor mallorca'],
                'Mallorca',
                'mallorca',
                'Independientes',
                'Zona Sur y Centro',
            );
        });
    }

    public function down(): void
    {
        // Delegations cannot be safely split back after their stock history has been merged.
    }

    /**
     * @param array<int, string> $keys
     */
    private function merge(
        array $keys,
        string $canonicalName,
        string $targetKey,
        string $commercialGroup,
        string $zone,
    ): void {
        $delegations = DB::table('stock_delegations')
            ->whereIn('normalized_key', $keys)
            ->orderByRaw('CASE WHEN normalized_key = ? THEN 0 ELSE 1 END', [$targetKey])
            ->orderBy('id')
            ->get();

        if ($delegations->isEmpty()) {
            return;
        }

        $target = $delegations->first();
        $sourceIds = $delegations->pluck('id')->filter(fn ($id): bool => (int) $id !== (int) $target->id)->values();

        foreach (['salesforce_vehicles', 'stock_daily_snapshots', 'salesforce_sale_snapshots'] as $table) {
            if ($sourceIds->isNotEmpty()) {
                DB::table($table)
                    ->whereIn('stock_delegation_id', $sourceIds)
                    ->update(['stock_delegation_id' => $target->id]);
            }
        }

        $capacity = $delegations->pluck('capacity_total')->filter(fn ($value): bool => $value !== null)->max();
        $capacitySource = $delegations->first(fn ($delegation) => $delegation->capacity_total !== null);
        $salesforceSource = $delegations->first(fn ($delegation) => filled($delegation->salesforce_id))
            ?? $delegations->first(fn ($delegation) => filled($delegation->salesforce_name));

        if ($sourceIds->isNotEmpty()) {
            DB::table('stock_delegations')->whereIn('id', $sourceIds)->delete();
        }

        DB::table('stock_delegations')->where('id', $target->id)->update([
            'canonical_name' => $canonicalName,
            'normalized_key' => $targetKey,
            'salesforce_id' => $salesforceSource?->salesforce_id,
            'salesforce_name' => $salesforceSource?->salesforce_name,
            'commercial_group' => $commercialGroup,
            'zone' => $zone,
            'capacity_total' => $capacity,
            'capacity_source_name' => $capacitySource?->capacity_source_name,
            'capacity_updated_at' => $capacitySource?->capacity_updated_at,
            'is_commercial' => true,
            'updated_at' => now(),
        ]);
    }
};
