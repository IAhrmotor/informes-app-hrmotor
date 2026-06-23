<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

class LeadDashboardController extends Controller
{
    public function index(Request $request)
    {
        return view('reports.leads.index', [
            'reportUserCanExport' => ReportUserAccess::canExport($request),
        ]);
    }
}
