<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommercialCommissionClosure;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommissionMonthResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommercialCommissionApiController extends Controller
{
    public function __construct(
        private readonly CommercialCommissionDashboardService $dashboard,
        private readonly CommercialCommissionClosureService $closures,
        private readonly CommissionMonthResolver $monthResolver,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'salesforce_id' => [
                'required',
                'string',
                'max:64',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && trim($value) === '') {
                        $fail('El campo salesforce_id es obligatorio.');
                    }
                },
            ],
            'month' => [
                'sometimes',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $value) !== 1) {
                        $fail('El campo month debe ser un mes válido en formato YYYY-MM.');

                        return;
                    }

                    try {
                        $month = CarbonImmutable::createFromFormat('!Y-m', $value, config('app.timezone'));
                    } catch (\Throwable) {
                        $fail('El campo month debe ser un mes válido en formato YYYY-MM.');

                        return;
                    }

                    if ($month->format('Y-m') !== $value || $month->year < 1) {
                        $fail('El campo month debe ser un mes válido en formato YYYY-MM.');

                        return;
                    }

                    if ($month->startOfMonth()->greaterThan(CarbonImmutable::now(config('app.timezone'))->startOfMonth())) {
                        $fail('El campo month no puede ser un mes futuro.');
                    }
                },
            ],
        ]);

        $commercialId = trim((string) $validated['salesforce_id']);
        $hasExplicitMonth = $request->exists('month');
        $selectedMonth = $this->monthResolver->resolve(
            $hasExplicitMonth ? (string) $validated['month'] : null,
        );
        $result = $this->monthlyResult($commercialId, $selectedMonth);

        if (! $result['available']) {
            return $this->unavailableResponse();
        }

        if ($result['row'] === null && ! $this->dashboard->hasEligibleCommercial($commercialId)) {
            return response()->json(['message' => 'Comercial no encontrado.'], 404);
        }

        $response = [
            'commercial_id' => $commercialId,
            'month' => $result['month'],
            'month_label' => $result['month_label'],
            'economic_status' => $result['economic_status'],
            'has_data' => $result['row'] !== null,
            'row' => $result['row'],
        ];

        if (! $hasExplicitMonth) {
            $previousMonth = $selectedMonth->subMonthNoOverflow()->startOfMonth();
            $previous = $this->monthlyResult($commercialId, $previousMonth);

            if (! $previous['available']) {
                return $this->unavailableResponse();
            }

            $response['current_month'] = [
                'month' => $result['month'],
                'month_label' => $result['month_label'],
                'final_commission' => $result['row']['final_commission'] ?? 0.0,
            ];
            $response['previous_closed_month'] = [
                'month' => $previous['month'],
                'month_label' => $previous['month_label'],
                'final_commission' => $previous['row']['final_commission'] ?? 0.0,
            ];
        }

        return response()->json($response, options: JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @return array{month: string, month_label: string, economic_status: string, available: bool, row: ?array<string, mixed>}
     */
    private function monthlyResult(string $commercialId, CarbonImmutable $month): array
    {
        $monthKey = $month->format('Y-m');
        $snapshot = $this->closures->definitiveSnapshot(
            $monthKey,
            CommercialCommissionClosure::SCOPE_COMMERCIALS,
        );

        if ($snapshot !== null) {
            $payload = is_array($snapshot['commercials'] ?? null)
                ? $snapshot['commercials']
                : [];
            $economicStatus = CommercialCommissionClosure::STATUS_DEFINITIVE;
        } else {
            $payload = $this->dashboard->build(
                $monthKey,
                includeSummaryRows: true,
                includeDelegationRows: false,
                includeDetails: true,
            );

            if (($payload['ready'] ?? null) !== true) {
                return [
                    'month' => (string) ($payload['month'] ?? $monthKey),
                    'month_label' => (string) ($payload['month_label'] ?? $month->translatedFormat('F Y')),
                    'economic_status' => (string) ($payload['economic_status'] ?? ''),
                    'available' => false,
                    'row' => null,
                ];
            }

            $economicStatus = (string) $this->closures->status(
                $monthKey,
                CommercialCommissionClosure::SCOPE_COMMERCIALS,
            )['status'];
        }

        $row = collect($payload['summary_rows'] ?? [])
            ->first(static fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['commercial_id'] ?? null) === $commercialId);

        return [
            'month' => (string) ($payload['month'] ?? $monthKey),
            'month_label' => (string) ($payload['month_label'] ?? $month->translatedFormat('F Y')),
            'economic_status' => $economicStatus,
            'available' => true,
            'row' => is_array($row) ? $row : null,
        ];
    }

    private function unavailableResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Las comisiones no están disponibles para el mes solicitado.',
        ], 503);
    }
}
