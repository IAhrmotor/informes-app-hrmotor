<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_opportunities', 'report_owner_delegation')) {
                $table->string('report_owner_delegation')
                    ->nullable()
                    ->after('owner_delegation')
                    ->index('sf_opps_report_owner_delegation_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_opportunities', 'report_owner_delegation')) {
                return;
            }

            try {
                $table->dropIndex('sf_opps_report_owner_delegation_idx');
            } catch (Throwable) {
            }

            $table->dropColumn('report_owner_delegation');
        });
    }
};
