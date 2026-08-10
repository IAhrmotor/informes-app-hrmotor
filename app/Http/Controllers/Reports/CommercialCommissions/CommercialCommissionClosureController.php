<?php

namespace App\Http\Controllers\Reports\CommercialCommissions;

use App\Http\Controllers\Controller;
use App\Models\CommercialCommissionAdjustment;
use App\Models\ReportUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Support\ReportUserAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommercialCommissionClosureController extends Controller
{
    public function status(Request $request, CommercialCommissionClosureService $closures): JsonResponse
    {
        abort_unless(ReportUserAccess::canViewCommercialCommissions($request), 403);

        return response()->json([
            'ok' => true,
            'closures' => $closures->statuses($request->query('month')),
        ]);
    }

    public function prepare(Request $request, CommercialCommissionClosureService $closures): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageEconomicClosures($request), 403);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'closure_scope' => ['required', 'in:commercials,delegations,area_manager'],
            'components.sales' => ['accepted'],
            'components.purchases' => ['accepted'],
            'components.cancellations' => ['accepted'],
            'components.reviews' => ['accepted'],
            'components.adjustments' => ['accepted'],
        ]);
        $closures->prepare($data['month'], $data['closure_scope'], $data['components'], $this->currentUser($request));

        return back()->with('status', 'Mes preparado y pendiente de aprobación.');
    }

    public function approve(Request $request, CommercialCommissionClosureService $closures): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageEconomicClosures($request), 403);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'closure_scope' => ['required', 'in:commercials,delegations,area_manager']]);
        $closures->approve($data['month'], $data['closure_scope'], $this->currentUser($request));

        return back()->with('status', 'Cierre económico aprobado y fotografiado como definitivo.');
    }

    public function reopen(Request $request, CommercialCommissionClosureService $closures): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageEconomicClosures($request), 403);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'closure_scope' => ['required', 'in:commercials,delegations,area_manager'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $closures->reopen($data['month'], $data['closure_scope'], $data['reason'], $this->currentUser($request));

        return back()->with('status', 'Mes reabierto. El cierre anterior permanece en el historial.');
    }

    public function storeAdjustment(Request $request, CommercialCommissionClosureService $closures): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageEconomicClosures($request), 403);
        $data = $request->validate([
            'operation_id' => ['required', 'string', 'max:64'],
            'original_month' => ['required', 'date_format:Y-m'],
            'application_month' => ['nullable', 'date_format:Y-m'],
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        CommercialCommissionAdjustment::query()->create([
            'operation_id' => $data['operation_id'],
            'original_month' => $data['original_month'],
            'application_month' => $closures->nextOpenMonth($data['application_month'] ?? null),
            'amount' => $data['amount'],
            'reason' => $data['reason'],
            'status' => 'pending',
            'created_by' => $this->currentUser($request)->id,
            'source_context' => ['source' => 'manual_audited_adjustment'],
        ]);

        return back()->with('status', 'Ajuste económico registrado para el siguiente mes abierto.');
    }

    private function currentUser(Request $request): ReportUser
    {
        $user = ReportUser::query()->find((int) $request->session()->get('report_user_id'));
        abort_unless($user !== null, 403, 'No se ha podido resolver el usuario de auditoría.');

        return $user;
    }
}
