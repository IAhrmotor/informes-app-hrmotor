<?php

namespace App\Http\Controllers\Reports\Calls;

use App\Http\Controllers\Controller;

class CallDashboardController extends Controller
{
    public function index()
    {
        return view('reports.calls.index');
    }
}
