<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialCommissionClosure extends Model
{
    public const STATUS_PROVISIONAL = 'provisional';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_DEFINITIVE = 'definitive';
    public const STATUS_REOPENED = 'reopened';

    protected $fillable = [
        'month', 'status', 'component_statuses', 'issues', 'data_cutoff_at', 'formula_version',
        'approved_by', 'approved_at', 'reopened_by', 'reopened_at', 'reopen_reason', 'snapshot_version',
    ];

    protected $casts = [
        'component_statuses' => 'array',
        'issues' => 'array',
        'data_cutoff_at' => 'datetime',
        'approved_at' => 'datetime',
        'reopened_at' => 'datetime',
        'snapshot_version' => 'integer',
    ];

    public function snapshots(): HasMany
    {
        return $this->hasMany(CommercialCommissionSnapshot::class, 'closure_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(ReportUser::class, 'approved_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(ReportUser::class, 'reopened_by');
    }
}
