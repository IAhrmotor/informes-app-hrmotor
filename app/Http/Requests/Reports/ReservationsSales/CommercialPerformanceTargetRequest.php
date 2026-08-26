<?php

namespace App\Http\Requests\Reports\ReservationsSales;

use App\Support\ReportUserAccess;
use Illuminate\Foundation\Http\FormRequest;

class CommercialPerformanceTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ReportUserAccess::canViewCommercialPerformance($this);
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'reservations_target' => ['required', 'integer', 'min:1'],
        ];
    }
}
