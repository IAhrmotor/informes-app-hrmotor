<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesforceLead extends Model
{
    protected $fillable = [
        'salesforce_id',
        'name',
        'created_date',
        'last_activity_date',
        'status',
        'owner_id',
        'owner_name',
        'persona_que_trabajo_id',
        'persona_que_trabajo_name',
        'propietario_descarte_id',
        'propietario_descarte_name',
        'fecha_asignacion',
        'fuente_origen',
        'medio_origen',
        'medio_nuevo',
        'fuente_nuevo',
        'remitente_lead',
        'portal_text',
        'delegacion_encargada_text',
        'delegacion_encargada_bueno',
        'delegacion_encargada',
        'delegacion_original',
        'raw_payload',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'last_activity_date' => 'date',
        'fecha_asignacion' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function activitySummary(): HasOne
    {
        return $this->hasOne(SalesforceLeadActivitySummary::class, 'lead_salesforce_id', 'salesforce_id');
    }
}
