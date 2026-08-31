<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'salesforce_delegation_manager_history';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $missing = collect([
            'coverage_from',
            'coverage_to',
            'evidence_reference',
            'recorded_by',
        ])->reject(fn (string $column): bool => Schema::hasColumn(self::TABLE, $column));

        if ($missing->isNotEmpty()) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($missing): void {
                if ($missing->contains('coverage_from')) {
                    $table->timestamp('coverage_from')->nullable();
                }
                if ($missing->contains('coverage_to')) {
                    $table->timestamp('coverage_to')->nullable();
                }
                if ($missing->contains('evidence_reference')) {
                    $table->string('evidence_reference')->nullable();
                }
                if ($missing->contains('recorded_by')) {
                    $table->string('recorded_by')->nullable();
                }
            });
        }

        // Legacy observations start at their existing effective timestamp. This
        // does not claim an end date or promote unverified evidence.
        DB::table(self::TABLE)
            ->whereNull('coverage_from')
            ->update(['coverage_from' => DB::raw('effective_at')]);

        if (! Schema::hasIndex(self::TABLE, 'sf_deleg_mgr_coverage_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['delegation_key', 'coverage_from', 'coverage_to'], 'sf_deleg_mgr_coverage_idx');
            });
        }
    }

    public function down(): void
    {
        // Compatibility migration: columns may belong to the create migration
        // on fresh installations, so rolling back must not remove shared data.
    }
};
