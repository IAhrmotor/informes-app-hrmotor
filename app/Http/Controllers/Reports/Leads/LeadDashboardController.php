<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;

class LeadDashboardController extends Controller
{
    public function index()
    {
        return view('reports.leads.index');
    }
}