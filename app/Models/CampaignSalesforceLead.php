<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignSalesforceLead extends Model
{
    protected $fillable = [
        'salesforce_id',
        'name',
        'created_date',
        'status',
        'owner_id',
        'owner_name',
        'phone',
        'mobile_phone',
        'email',
        'is_converted',
        'converted_date',
        'converted_account_id',
        'converted_contact_id',
        'converted_opportunity_id',
        'fuente_origen',
        'medio_origen',
        'source_origin_new',
        'medium_origin_new',
        'channel_new',
        'delegation_origin_new',
        'campaign_acquired',
        'acquired_id',
        'content_acquired',
        'acquired_source_legacy',
        'acquired_medium_legacy',
        'utm_campaign_new',
        'utm_id_new',
        'utm_source_new',
        'utm_medium_new',
        'utm_content_new',
        'field_resolution',
        'vehicle_interest',
        'delegacion_encargada_text',
        'delegacion_encargada_id',
        'delegacion_encargada_bueno',
        'raw_payload',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'is_converted' => 'boolean',
        'converted_date' => 'datetime',
        'field_resolution' => 'array',
        'raw_payload' => 'array',
    ];
}
