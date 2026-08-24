<?php

namespace App\Http\Controllers\Reports\SeoAnalytics;

use App\Http\Controllers\Controller;
use App\Services\SeoAnalytics\SeoExecutiveEmailSettingsService;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SeoExecutiveEmailSettingsController extends Controller
{
    public function update(
        Request $request,
        SeoExecutiveEmailSettingsService $settings,
    ): RedirectResponse {
        abort_unless(ReportUserAccess::canManageSeoExecutiveEmailSettings($request), 403);
        $actor = ReportUserAccess::reportUser($request);
        abort_unless($actor !== null, 403);

        $data = $request->validate([
            'email_recipients' => ['required', 'string', 'max:5000'],
        ]);
        $recipients = $settings->validateTextarea($data['email_recipients']);
        $settings->save($recipients, $actor->id);

        return redirect()
            ->route('reports.seo-analytics.settings.index')
            ->with('email_status', 'Destinatarios del correo ejecutivo actualizados.');
    }
}
