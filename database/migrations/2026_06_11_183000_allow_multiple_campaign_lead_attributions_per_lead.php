<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            try {
                $table->dropUnique('campaign_lead_attributions_lead_id_unique');
            } catch (\Throwable $exception) {
                // The index may already be absent in some environments.
            }

            try {
                $table->index('lead_id', 'campaign_lead_attr_lead_id_idx');
            } catch (\Throwable $exception) {
                // The index may already exist in some environments.
            }

            try {
                $table->index(['lead_id', 'opportunity_id'], 'campaign_lead_attr_lead_opportunity_idx');
            } catch (\Throwable $exception) {
                // The index may already exist in some environments.
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            try {
                $table->dropIndex('campaign_lead_attr_lead_opportunity_idx');
            } catch (\Throwable $exception) {
                // The index may already be absent in some environments.
            }

            try {
                $table->dropIndex('campaign_lead_attr_lead_id_idx');
            } catch (\Throwable $exception) {
                // The index may already be absent in some environments.
            }

            try {
                $table->unique('lead_id', 'campaign_lead_attributions_lead_id_unique');
            } catch (\Throwable $exception) {
                // The unique index may already exist in some environments.
            }
        });
    }
};
