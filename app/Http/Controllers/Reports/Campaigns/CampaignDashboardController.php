<?php

namespace App\Http\Controllers\Reports\Campaigns;

use App\Http\Controllers\Controller;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

class CampaignDashboardController extends Controller
{
    public function index(Request $request)
    {
        if (! ReportUserAccess::canViewCampaigns($request)) {
            return redirect()->route('reports.leads.index');
        }

        return view('reports.campaigns.index', [
            'reportUserRole' => ReportUserAccess::role($request),
            'reportUserCanExport' => ReportUserAccess::canExport($request),
        ]);
    }
}
