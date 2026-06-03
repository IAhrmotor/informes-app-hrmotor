<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignTypeMapping extends Model
{
    public const TYPE_VENTA = 'venta';
    public const TYPE_TASACION = 'tasacion';

    protected $fillable = [
        'platform',
        'campaign_id',
        'campaign_name',
        'campaign_type',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
