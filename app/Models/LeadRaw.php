<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeadRaw extends Model
{
    protected $table = 'leads_raw';

    protected $fillable = [
        'salesforce_id',
        'lead_created_at',
        'status',

        'owner_id',
        'owner_name',
        'owner_delegation',

        'worked_by_id',
        'worked_by_name',

        'discarded_owner_id',
        'discarded_owner_name',

        'medio_nuevo',
        'fuente_nuevo',

        'portal',
        'portal_value',
        'lea_sel_fuente_origen',
        'lea_sel_medio_origen',

        'remitente_lead',

        'delegacion_encargada_text',
        'delegacion_encargada_bueno',
        'delegacion_encargada',
        'delegacion',

        'assigned_at',
        'first_task_event_at',
        'last_task_event_at',

        'raw_payload',
    ];

    protected $casts = [
        'lead_created_at' => 'datetime',
        'assigned_at' => 'datetime',
        'first_task_event_at' => 'datetime',
        'last_task_event_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function normalized(): HasOne
    {
        return $this->hasOne(LeadNormalized::class, 'lead_raw_id');
    }
}