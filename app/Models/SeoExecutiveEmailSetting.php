<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoExecutiveEmailSetting extends Model
{
    public const MODULE = 'seo';

    protected $fillable = [
        'module_key',
        'recipients',
        'updated_by_report_user_id',
    ];

    protected $casts = [
        'recipients' => 'array',
    ];
}
