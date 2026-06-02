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
        'campaign_acquired',
        'acquired_id',
        'content_acquired',
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
        'raw_payload' => 'array',
    ];
}
