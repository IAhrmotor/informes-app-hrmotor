<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignOperationalClassification extends Model
{
    protected $guarded = [];

    protected $casts = ['classified_at' => 'datetime'];
}
