<?php

namespace App\Http\Requests\Reports\ReservationsSales;

use App\Support\ReportUserAccess;
use Illuminate\Foundation\Http\FormRequest;

class CommercialPerformanceAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ReportUserAccess::canAuditCommercialPerformance($this);
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'zone' => ['nullable', 'string', 'max:120'],
            'delegation' => ['nullable', 'string', 'max:120'],
            'commercial' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
