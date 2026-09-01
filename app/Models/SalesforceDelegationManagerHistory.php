<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesforceDelegationManagerHistory extends Model
{
    protected $table = 'salesforce_delegation_manager_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
            'coverage_from' => 'immutable_datetime',
            'coverage_to' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'history_verified' => 'boolean',
        ];
    }
}
