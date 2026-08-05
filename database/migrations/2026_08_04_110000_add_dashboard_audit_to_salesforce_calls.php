<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_calls', function (Blueprint $table): void {
            $table->boolean('included_in_dashboard')->default(true)->after('call_object')->index('sf_calls_dashboard_included_idx');
            $table->string('dashboard_exclusion_reason')->nullable()->after('included_in_dashboard');
            $table->string('classification_rule_version')->nullable()->after('dashboard_exclusion_reason')->index('sf_calls_rule_version_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_calls', function (Blueprint $table): void {
            $table->dropIndex('sf_calls_dashboard_included_idx');
            $table->dropIndex('sf_calls_rule_version_idx');
            $table->dropColumn(['included_in_dashboard', 'dashboard_exclusion_reason', 'classification_rule_version']);
        });
    }
};
