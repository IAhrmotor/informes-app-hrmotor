<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalAlert extends Model
{
    public const STATE_OPEN = 'open';

    public const STATE_RESOLVED = 'resolved';

    protected $fillable = [
        'fingerprint',
        'type',
        'severity',
        'source',
        'state',
        'message',
        'technical_identifier',
        'context',
        'first_detected_at',
        'last_detected_at',
        'occurrences',
        'resolution',
        'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'occurrences' => 'integer',
    ];
}
