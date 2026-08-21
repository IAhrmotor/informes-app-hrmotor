<?php

namespace App\Http\Controllers\Reports\SeoAnalytics;

use App\Http\Controllers\Controller;
use App\Services\SeoAnalytics\SeoAnalyticalRuleSetConflictException;
use App\Services\SeoAnalytics\SeoAnalyticalRuleSetService;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SeoAnalyticalRuleSettingsController extends Controller
{
    public function __construct(private readonly SeoAnalyticalRuleSetService $ruleSets) {}

    public function index(Request $request): View
    {
        abort_unless(ReportUserAccess::canManageSeoAnalyticalRules($request), 403);

        return view('reports.seo-analytics.settings', $this->ruleSets->settings());
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageSeoAnalyticalRules($request), 403);

        $data = $request->validate([
            'base_rule_set_id' => ['required', 'integer', 'min:1'],
            'base_version_number' => ['required', 'integer', 'min:1'],
            'change_reason' => ['required', 'string', 'min:1', 'max:500'],
            'rules' => ['required', 'array', 'size:6'],
        ]);
        $actor = ReportUserAccess::reportUser($request);
        abort_unless($actor !== null, 403);

        try {
            $created = $this->ruleSets->createVersion(
                (int) $data['base_rule_set_id'],
                (int) $data['base_version_number'],
                $data['rules'],
                (string) $data['change_reason'],
                $actor->id,
            );
        } catch (SeoAnalyticalRuleSetConflictException $exception) {
            return redirect()
                ->route('reports.seo-analytics.settings.index')
                ->withErrors(['base_version_number' => $exception->getMessage()]);
        }

        return redirect()
            ->route('reports.seo-analytics.settings.index')
            ->with('status', "Umbrales actualizados. {$created->version_key} ya esta activa.");
    }
}
