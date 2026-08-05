<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['campaign_attributions', 'campaign_lead_attributions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->json('attribution_candidates')->nullable();
                $table->timestamp('first_touch_at')->nullable();
                $table->boolean('is_ambiguous')->default(false)->index();
                $table->string('attribution_rule_version')->default('2026-08-05.1');
            });
        }

        Schema::create('campaign_unresolved_attributions', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type');
            $table->string('entity_salesforce_id');
            $table->enum('status', ['ambiguous', 'unattributed']);
            $table->string('reason');
            $table->json('candidates')->nullable();
            $table->string('rule_version');
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->unique(['entity_type', 'entity_salesforce_id'], 'campaign_unresolved_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_unresolved_attributions');
        foreach (['campaign_attributions', 'campaign_lead_attributions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['attribution_candidates', 'first_touch_at', 'is_ambiguous', 'attribution_rule_version']);
            });
        }
    }
};
