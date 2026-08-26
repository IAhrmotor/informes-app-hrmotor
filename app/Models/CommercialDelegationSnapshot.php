<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialDelegationSnapshot extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $snapshot): void {
            $snapshot->open_marker = $snapshot->observed_until === null ? 1 : null;
        });
    }

    protected $fillable = [
        'salesforce_user_id',
        'delegation',
        'zone',
        'observed_from',
        'observed_until',
        'open_marker',
        'source',
    ];

    protected $casts = [
        'observed_from' => 'datetime',
        'observed_until' => 'datetime',
        'open_marker' => 'integer',
    ];
}
