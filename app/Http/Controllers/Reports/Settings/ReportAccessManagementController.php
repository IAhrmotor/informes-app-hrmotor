<?php

namespace App\Http\Controllers\Reports\Settings;

use App\Http\Controllers\Controller;
use App\Models\ReportAccessSetting;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportAccessManagementController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ReportUserAccess::canManageReportUsers($request)) {
            return redirect()->route('reports.index');
        }

        return view('reports.settings.access', [
            'reportUserRole' => ReportUserAccess::role($request),
            'reportDefinitions' => ReportUserAccess::reportDefinitions(),
            'minimumRoles' => ReportUserAccess::minimumRolesByReport(),
            'roleOptions' => ReportUserAccess::roleOptions(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (! ReportUserAccess::canManageReportUsers($request)) {
            return redirect()->route('reports.index');
        }

        $definitions = ReportUserAccess::reportDefinitions();
        $rules = [];

        foreach (array_keys($definitions) as $reportKey) {
            $rules["minimum_roles.$reportKey"] = ['required', 'string', Rule::in(array_keys(ReportUserAccess::roleOptions()))];
        }

        $data = $request->validate($rules);

        foreach ($definitions as $reportKey => $definition) {
            ReportAccessSetting::query()->updateOrCreate(
                ['report_key' => $reportKey],
                ['minimum_role' => $data['minimum_roles'][$reportKey]]
            );
        }

        ReportUserAccess::flushResolvedSettings();

        return redirect()
            ->route('reports.access-settings.index')
            ->with('status', 'Permisos por informe actualizados correctamente.');
    }
}
