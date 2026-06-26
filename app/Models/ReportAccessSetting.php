<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportAccessSetting extends Model
{
    protected $fillable = [
        'report_key',
        'minimum_role',
    ];
}
