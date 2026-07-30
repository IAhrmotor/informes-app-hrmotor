<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_delegations')->update(['is_commercial' => true]);

        DB::table('stock_delegations')
            ->whereIn('normalized_key', [
                'fdfgfg',
                'hr motor mantenimiento',
                'hr motor pendiente de entrar',
                'hr new cars fuera de stock',
            ])
            ->update(['is_commercial' => false]);
    }

    public function down(): void
    {
        // Data classification cannot be safely inferred back to its previous state.
    }
};
