<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Services\Reports\Leads\SalesforceLeadDashboardDatasetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadDashboardDataController extends Controller
{
    public function __construct(
        private readonly SalesforceLeadDashboardDatasetService $dataset,
    ) {
    }

    public function resumen(Request $request): JsonResponse
    {
        return response()->json($this->dataset->summary($request));
    }

    public function kpis(Request $request): JsonResponse
    {
        return $this->resumen($request);
    }

    public function portales(Request $request): JsonResponse
    {
        return response()->json($this->dataset->portalRows($request));
    }

    public function portalDetalle(Request $request): JsonResponse
    {
        return $this->portales($request);
    }

    public function delegaciones(Request $request): JsonResponse
    {
        return response()->json($this->dataset->delegationRows($request));
    }

    public function comerciales(Request $request): JsonResponse
    {
        return response()->json($this->dataset->commercialRows($request));
    }

    public function comparativa(Request $request): JsonResponse
    {
        return $this->resumen($request);
    }

    public function calidadDato(Request $request): JsonResponse
    {
        return response()->json([
            'items' => [],
            'message' => 'La calidad de dato CSV no se muestra en la fase Salesforce del dashboard.',
        ]);
    }
}
