<?php

namespace App\Services\SeoAnalytics;

use App\Mail\SeoExecutiveDailyReportMail;
use Illuminate\Support\Facades\Mail;

class SeoExecutiveMailSender
{
    /** @param array<string, mixed> $payload */
    public function send(string $recipient, array $payload): void
    {
        Mail::to($recipient)->send(new SeoExecutiveDailyReportMail($payload));
    }
}
