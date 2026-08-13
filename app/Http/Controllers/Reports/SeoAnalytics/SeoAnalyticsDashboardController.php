<?php

namespace App\Http\Controllers\Reports\SeoAnalytics;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class SeoAnalyticsDashboardController extends Controller
{
    public function index(): View
    {
        return view('reports.seo-analytics.index');
    }
}
