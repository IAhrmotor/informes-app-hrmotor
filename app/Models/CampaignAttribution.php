<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignAttribution extends Model
{
    protected $fillable = [
        'lead_id',
        'opportunity_id',
        'platform',
        'account_id',
        'campaign_id',
        'campaign_name',
        'campaign_name_key',
        'source_acquired',
        'medium_acquired',
        'campaign_acquired',
        'acquired_id',
        'acquired_id_key',
        'content_acquired',
        'content_acquired_key',
        'vehicle_interest',
        'lead_status',
        'lead_created_at',
        'opportunity_created_at',
        'reservation_date',
        'sale_date',
        'sale_amount',
        'has_opportunity',
        'has_reservation',
        'has_fallen_reservation',
        'has_sale',
        'lead_delegation',
        'lead_zone',
        'commercial_user_id',
        'commercial_user_name',
        'attribution_method',
        'attribution_confidence',
        'opportunity_attribution_method',
        'opportunity_attribution_confidence',
        'match_status',
        'campaign_source_type',
        'attribution_window_days',
    ];

    protected $casts = [
        'lead_created_at' => 'datetime',
        'opportunity_created_at' => 'datetime',
        'reservation_date' => 'date',
        'sale_date' => 'date',
        'sale_amount' => 'decimal:2',
        'has_opportunity' => 'boolean',
        'has_reservation' => 'boolean',
        'has_fallen_reservation' => 'boolean',
        'has_sale' => 'boolean',
        'attribution_window_days' => 'integer',
    ];
}
