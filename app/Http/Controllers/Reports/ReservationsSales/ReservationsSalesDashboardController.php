<?php

namespace App\Http\Controllers\Reports\ReservationsSales;

use App\Http\Controllers\Controller;

class ReservationsSalesDashboardController extends Controller
{
    public function index()
    {
        return view('reports.reservations-sales.index');
    }
}
