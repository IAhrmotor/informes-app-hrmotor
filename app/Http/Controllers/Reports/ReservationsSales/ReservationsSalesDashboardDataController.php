<?php

namespace App\Http\Controllers\Reports\ReservationsSales;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReservationsSales\ReservationsSalesDashboardDatasetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationsSalesDashboardDataController extends Controller
{
    public function __construct(
        private readonly ReservationsSalesDashboardDatasetService $dataset,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->dataset->summary($request));
    }

    public function commercials(Request $request): JsonResponse
    {
        return response()->json($this->dataset->commercialRows($request));
    }

    public function portals(Request $request): JsonResponse
    {
        return response()->json($this->dataset->portalRows($request));
    }
}
