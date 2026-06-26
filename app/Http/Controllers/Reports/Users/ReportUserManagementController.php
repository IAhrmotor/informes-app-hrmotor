<?php

namespace App\Http\Controllers\Reports\Users;

use App\Http\Controllers\Controller;
use App\Models\ReportUser;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportUserManagementController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ReportUserAccess::canManageReportUsers($request)) {
            return redirect()->route('reports.leads.index');
        }

        return view('reports.users.index', [
            'reportUserRole' => ReportUserAccess::role($request),
            'users' => ReportUser::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->orderBy('email')
                ->get(),
            'roleOptions' => ReportUser::roleOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! ReportUserAccess::canManageReportUsers($request)) {
            return redirect()->route('reports.leads.index');
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('report_users', 'email')],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'role' => ['required', 'string', Rule::in(ReportUser::availableRoles())],
            'is_active' => ['nullable', 'boolean'],
        ]);

        ReportUser::query()->create([
            'name' => $data['name'] ?: null,
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return redirect()
            ->route('reports.users.index')
            ->with('status', 'Usuario creado correctamente.');
    }

    public function edit(Request $request, ReportUser $reportUser): View|RedirectResponse
    {
        if (! ReportUserAccess::canManageReportUsers($request)) {
            return redirect()->route('reports.leads.index');
        }

        return view('reports.users.edit', [
            'reportUserRole' => ReportUserAccess::role($request),
            'managedUser' => $reportUser,
            'roleOptions' => ReportUser::roleOptions(),
        ]);
    }

    public function update(Request $request, ReportUser $reportUser): RedirectResponse
    {
        if (! ReportUserAccess::canManageReportUsers($request)) {
            return redirect()->route('reports.leads.index');
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('report_users', 'email')->ignore($reportUser->id)],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'role' => ['required', 'string', Rule::in(ReportUser::availableRoles())],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $isActive = (bool) ($data['is_active'] ?? false);
        $role = (string) $data['role'];
        $currentUserId = (int) $request->session()->get('report_user_id');

        if ($currentUserId === $reportUser->id && ! $isActive) {
            return back()->withErrors(['is_active' => 'No puedes desactivar tu propio usuario.'])->withInput();
        }

        if ($currentUserId === $reportUser->id && $role !== ReportUser::ROLE_ADMIN) {
            return back()->withErrors(['role' => 'No puedes quitarte a ti mismo el rol de administrador.'])->withInput();
        }

        if ($this->wouldRemoveLastActiveAdmin($reportUser, $role, $isActive)) {
            return back()->withErrors(['role' => 'Debe existir al menos un administrador activo.'])->withInput();
        }

        $payload = [
            'name' => $data['name'] ?: null,
            'email' => $data['email'],
            'role' => $role,
            'is_active' => $isActive,
        ];

        if (filled($data['password'] ?? null)) {
            $payload['password'] = $data['password'];
        }

        $reportUser->fill($payload)->save();

        if ($currentUserId === $reportUser->id) {
            $request->session()->put('report_user_email', $reportUser->email);
            $request->session()->put('informes_user', $reportUser->email);
            $request->session()->put('report_user_role', $reportUser->role);
        }

        return redirect()
            ->route('reports.users.index')
            ->with('status', 'Usuario actualizado correctamente.');
    }

    public function destroy(Request $request, ReportUser $reportUser): RedirectResponse
    {
        if (! ReportUserAccess::canManageReportUsers($request)) {
            return redirect()->route('reports.leads.index');
        }

        $currentUserId = (int) $request->session()->get('report_user_id');

        if ($currentUserId === $reportUser->id) {
            return back()->withErrors(['delete' => 'No puedes eliminar tu propio usuario.']);
        }

        if ($this->wouldDeleteLastActiveAdmin($reportUser)) {
            return back()->withErrors(['delete' => 'Debe existir al menos un administrador activo.']);
        }

        $reportUser->delete();

        return redirect()
            ->route('reports.users.index')
            ->with('status', 'Usuario eliminado correctamente.');
    }

    private function wouldRemoveLastActiveAdmin(ReportUser $reportUser, string $newRole, bool $newIsActive): bool
    {
        if ($reportUser->role !== ReportUser::ROLE_ADMIN || ! $reportUser->is_active) {
            return false;
        }

        if ($newRole === ReportUser::ROLE_ADMIN && $newIsActive) {
            return false;
        }

        return ReportUser::query()
            ->where('role', ReportUser::ROLE_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($reportUser->id)
            ->count() === 0;
    }

    private function wouldDeleteLastActiveAdmin(ReportUser $reportUser): bool
    {
        if ($reportUser->role !== ReportUser::ROLE_ADMIN || ! $reportUser->is_active) {
            return false;
        }

        return ReportUser::query()
            ->where('role', ReportUser::ROLE_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($reportUser->id)
            ->count() === 0;
    }
}
