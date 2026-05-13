<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceLeadActivitySummary extends Model
{
    protected $fillable = [
        'lead_salesforce_id',
        'total_actividades',
        'total_tasks',
        'total_events',
        'fecha_primer_contacto',
        'fecha_ultima_actividad',
        'primer_contacto_activity_id',
        'primer_contacto_tipo',
        'primer_contacto_subject',
        'primer_contacto_owner_id',
        'primer_contacto_owner_name',
        'primer_contacto_created_by_id',
        'primer_contacto_created_by_name',
    ];

    protected $casts = [
        'fecha_primer_contacto' => 'datetime',
        'fecha_ultima_actividad' => 'datetime',
    ];
}
