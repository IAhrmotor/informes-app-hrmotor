<?php

namespace App\Http\Controllers\Reports\SeoAnalytics;

use App\Http\Controllers\Controller;
use App\Services\SeoAnalytics\SeoIntegrationReadinessService;
use Illuminate\View\View;

class SeoAnalyticsDashboardController extends Controller
{
    public function index(SeoIntegrationReadinessService $readiness): View
    {
        return view('reports.seo-analytics.index', [
            'sources' => $readiness->sources(),
        ]);
    }
}
