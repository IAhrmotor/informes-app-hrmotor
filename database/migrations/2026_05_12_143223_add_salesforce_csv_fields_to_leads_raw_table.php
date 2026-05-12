<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads_raw', function (Blueprint $table) {
            if (! Schema::hasColumn('leads_raw', 'owner_delegation')) {
                $table->string('owner_delegation')->nullable()->after('owner_name');
            }

            if (! Schema::hasColumn('leads_raw', 'worked_by_id')) {
                $table->string('worked_by_id')->nullable()->after('owner_delegation');
            }

            if (! Schema::hasColumn('leads_raw', 'worked_by_name')) {
                $table->string('worked_by_name')->nullable()->after('worked_by_id');
            }

            if (! Schema::hasColumn('leads_raw', 'discarded_owner_id')) {
                $table->string('discarded_owner_id')->nullable()->after('worked_by_name');
            }

            if (! Schema::hasColumn('leads_raw', 'discarded_owner_name')) {
                $table->string('discarded_owner_name')->nullable()->after('discarded_owner_id');
            }

            if (! Schema::hasColumn('leads_raw', 'portal_value')) {
                $table->string('portal_value')->nullable()->after('portal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads_raw', function (Blueprint $table) {
            foreach ([
                'owner_delegation',
                'worked_by_id',
                'worked_by_name',
                'discarded_owner_id',
                'discarded_owner_name',
                'portal_value',
            ] as $column) {
                if (Schema::hasColumn('leads_raw', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};