<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterFormSenderMapping extends Model
{
    protected $fillable = [
        'portal_original',
        'portal_value',
        'sender_email',
        'receiver_account',
        'type',
        'delegation_name',
        'commercial_group',
        'status',
        'valid_from',
        'valid_to',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];
}