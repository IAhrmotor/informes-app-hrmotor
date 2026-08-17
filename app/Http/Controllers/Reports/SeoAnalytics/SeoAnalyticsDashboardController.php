<?php

namespace App\Http\Controllers\Reports\SeoAnalytics;

use App\Http\Controllers\Controller;
use App\Services\SeoAnalytics\SeoAnalyticsDatasetService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SeoAnalyticsDashboardController extends Controller
{
    public function index(Request $request, SeoAnalyticsDatasetService $dataset): View
    {
        return view('reports.seo-analytics.index', $dataset->build(
            $request->query('range'),
            $request->query('section'),
        ));
    }
}
