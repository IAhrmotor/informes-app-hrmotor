<?php

use App\Http\Controllers\Api\CommercialCommissionApiController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'internal.api.audit',
    'commissions.api.auth',
    'internal.api.throttle',
])->group(function () {
    Route::get('/comisiones_comercial', [CommercialCommissionApiController::class, 'show'])
        ->name('api.commercial-commissions.show');
});
