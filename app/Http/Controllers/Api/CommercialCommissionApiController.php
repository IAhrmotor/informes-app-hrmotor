<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommercialCommissionApiController extends Controller
{
    public function __construct(
        private readonly CommercialCommissionDashboardService $dashboard,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'salesforce_id' => ['required', 'string'],
        ]);

        $commercialId = trim((string) $validated['salesforce_id']);
        $currentMonth = CarbonImmutable::now()->startOfMonth();
        $previousClosedMonth = $currentMonth->subMonthNoOverflow()->startOfMonth();
        $current = $this->dashboard->finalCommissionForCommercial($commercialId, $currentMonth);
        $previous = $this->dashboard->finalCommissionForCommercial($commercialId, $previousClosedMonth);

        return response()->json([
            'commercial_id' => $commercialId,
            'current_month' => [
                'month' => $currentMonth->format('Y-m'),
                'month_label' => $currentMonth->translatedFormat('F Y'),
                'final_commission' => $current['final_commission'] ?? 0.0,
            ],
            'previous_closed_month' => [
                'month' => $previousClosedMonth->format('Y-m'),
                'month_label' => $previousClosedMonth->translatedFormat('F Y'),
                'final_commission' => $previous['final_commission'] ?? 0.0,
            ],
        ]);
    }
}
