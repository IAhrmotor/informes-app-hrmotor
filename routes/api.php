<?php

use App\Http\Controllers\Api\CommercialCommissionApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('commissions.api.auth')->group(function () {
    Route::get('/comisiones_comercial', [CommercialCommissionApiController::class, 'show'])
        ->name('api.commercial-commissions.show');
});
