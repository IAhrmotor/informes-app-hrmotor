<?php

namespace App\Http\Controllers\Reports\Campaigns;

use App\Http\Controllers\Controller;

class CampaignDashboardController extends Controller
{
    public function index()
    {
        return view('reports.campaigns.index');
    }
}
