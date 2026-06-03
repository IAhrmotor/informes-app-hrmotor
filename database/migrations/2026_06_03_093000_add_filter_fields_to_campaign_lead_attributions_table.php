<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            foreach ([
                'source_acquired' => ['string', 255],
                'medium_acquired' => ['string', 255],
                'campaign_acquired' => ['string', 255],
                'acquired_id' => ['string', 255],
                'content_acquired' => ['string', 255],
                'lead_status' => ['string', 255],
                'lead_delegation' => ['string', 255],
                'lead_zone' => ['string', 255],
                'commercial_user_id' => ['string', 255],
                'commercial_user_name' => ['string', 255],
                'vehicle_interest' => ['string', 255],
            ] as $column => [$type, $length]) {
                if (! Schema::hasColumn('campaign_lead_attributions', $column)) {
                    $table->{$type}($column, $length)->nullable()->after('has_opportunity');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            foreach ([
                'vehicle_interest',
                'commercial_user_name',
                'commercial_user_id',
                'lead_zone',
                'lead_delegation',
                'lead_status',
                'content_acquired',
                'acquired_id',
                'campaign_acquired',
                'medium_acquired',
                'source_acquired',
            ] as $column) {
                if (Schema::hasColumn('campaign_lead_attributions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
