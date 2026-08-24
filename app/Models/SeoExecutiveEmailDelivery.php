<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoExecutiveEmailDelivery extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'seo_executive_daily_report_id',
        'report_date',
        'recipient_email',
        'recipient_hash',
        'status',
        'attempt_count',
        'last_attempt_at',
        'sent_at',
        'error_message',
    ];

    protected $casts = [
        'report_date' => 'date',
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(SeoExecutiveDailyReport::class, 'seo_executive_daily_report_id');
    }
}
