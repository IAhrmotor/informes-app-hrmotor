<?php

namespace App\Http\Controllers\Reports\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\ReportUser;
use App\Services\Campaigns\CampaignInvestmentClosureService;
use App\Support\ReportUserAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignInvestmentClosureController extends Controller
{
    public function status(Request $request, CampaignInvestmentClosureService $closures): JsonResponse
    {
        abort_unless(ReportUserAccess::canViewCampaigns($request), 403);

        return response()->json($closures->status($request->string('month')->toString() ?: now()->toDateString()));
    }

    public function close(Request $request, CampaignInvestmentClosureService $closures): JsonResponse
    {
        abort_unless(ReportUserAccess::isAdmin($request), 403);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m-d']]);

        return $this->runClosureAction(fn (): array => $closures->close($data['month'], $this->reportUser($request)));
    }

    public function reopen(Request $request, CampaignInvestmentClosureService $closures): JsonResponse
    {
        abort_unless(ReportUserAccess::isAdmin($request), 403);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return $this->runClosureAction(fn (): array => $closures->reopen($data['month'], $data['reason'], $this->reportUser($request)));
    }

    private function reportUser(Request $request): ReportUser
    {
        $user = ReportUserAccess::reportUser($request);
        abort_unless($user !== null, 403);

        return $user;
    }

    private function runClosureAction(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (\LogicException|\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
