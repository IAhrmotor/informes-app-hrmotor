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
        'salesforce_last_modified_at',
        'synced_at',
        'is_deleted',
        'salesforce_deleted_at',
        'deletion_detection_source',
        'status',
        'record_type_name',
        'record_type_normalized',
        'owner_id',
        'owner_name',
        'appointment_setter_id',
        'appointment_setter_name',
        'persona_que_trabajo_id',
        'persona_que_trabajo_name',
        'propietario_descarte_id',
        'propietario_descarte_name',
        'fecha_asignacion',
        'appointment_capture_date',
        'appointment_call',
        'appointment_store',
        'appointment_attended_status',
        'store_commercial_id',
        'store_commercial_name',
        'candidate_status_formula',
        'fuente_origen',
        'medio_origen',
        'campaign_acquired',
        'acquired_id',
        'content_acquired',
        'vehicle_interest',
        'phone',
        'mobile_phone',
        'email',
        'is_converted',
        'converted_date',
        'converted_account_id',
        'converted_contact_id',
        'converted_opportunity_id',
        'medio_nuevo',
        'fuente_nuevo',
        'remitente_lead',
        'portal_text',
        'resolved_channel',
        'resolved_portal',
        'portal_resolution_source',
        'delegacion_encargada_text',
        'delegacion_encargada_bueno',
        'delegacion_encargada',
        'delegacion_original',
        'raw_payload',
    ];

    protected $casts = [
        'created_date' => 'datetime',
        'last_activity_date' => 'date',
        'salesforce_last_modified_at' => 'datetime',
        'synced_at' => 'datetime',
        'is_deleted' => 'boolean',
        'salesforce_deleted_at' => 'datetime',
        'fecha_asignacion' => 'datetime',
        'appointment_capture_date' => 'date',
        'appointment_call' => 'boolean',
        'appointment_store' => 'boolean',
        'is_converted' => 'boolean',
        'converted_date' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function activitySummary(): HasOne
    {
        return $this->hasOne(SalesforceLeadActivitySummary::class, 'lead_salesforce_id', 'salesforce_id');
    }
}
