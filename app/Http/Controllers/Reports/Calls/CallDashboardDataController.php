<?php

namespace App\Http\Controllers\Reports\Calls;

use App\Http\Controllers\Controller;
use App\Services\Reports\Calls\CallDashboardDatasetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallDashboardDataController extends Controller
{
    public function __construct(
        private readonly CallDashboardDatasetService $dataset,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->dataset->summary($request));
    }

    public function agents(Request $request): JsonResponse
    {
        return response()->json($this->dataset->agentRows($request));
    }

    public function delegations(Request $request): JsonResponse
    {
        return response()->json($this->dataset->delegationRows($request));
    }

    public function portals(Request $request): JsonResponse
    {
        return response()->json($this->dataset->portalRows($request));
    }
}
