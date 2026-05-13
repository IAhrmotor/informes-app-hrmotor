<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Models\MonthlyCommercialReportSnapshot;
use Illuminate\Http\JsonResponse;

class MonthlyCommercialReportDataController extends Controller
{
    public function summary(): JsonResponse
    {
        return $this->fromSnapshot(fn (array $payload) => [
            'periodos_estandar' => $payload['periodos_estandar'] ?? [],
            'resumen_global' => $payload['resumen_global'] ?? [],
            'resumen_ejecutivo' => $payload['resumen_ejecutivo'] ?? ['prioridades' => []],
            'calidad_dato' => $payload['calidad_dato'] ?? [],
        ]);
    }

    public function evolution(): JsonResponse
    {
        return $this->fromSnapshot(fn (array $payload) => $payload['evolucion_periodo_anterior'] ?? ['items' => []]);
    }

    public function kpis(): JsonResponse
    {
        return $this->fromSnapshot(fn (array $payload) => $payload['kpis_ejecutivos_exactos'] ?? []);
    }

    public function commercialPending(): JsonResponse
    {
        return $this->fromSnapshot(fn (array $payload) => [
            'items' => $payload['bolsa_viva']['comerciales'] ?? [],
        ]);
    }

    public function commercialPerformance(): JsonResponse
    {
        return $this->fromSnapshot(fn (array $payload) => [
            'items' => $payload['conversion_descarte']['comerciales'] ?? [],
        ]);
    }

    public function portals(): JsonResponse
    {
        return $this->fromSnapshot(fn (array $payload) => [
            'items' => $payload['conversion_descarte']['fuentes'] ?? [],
        ]);
    }

    public function delegations(): JsonResponse
    {
        return $this->fromSnapshot(fn (array $payload) => [
            'items' => $payload['conversion_descarte']['delegaciones'] ?? [],
        ]);
    }

    public function delegationPending(): JsonResponse
    {
        return $this->fromSnapshot(fn (array $payload) => [
            'items' => $payload['bolsa_viva']['delegaciones'] ?? [],
        ]);
    }

    private function fromSnapshot(callable $callback): JsonResponse
    {
        $snapshot = MonthlyCommercialReportSnapshot::query()
            ->latest('generated_at')
            ->latest('id')
            ->first();

        if ($snapshot === null) {
            return response()->json([
                'ok' => false,
                'message' => 'No hay informe mensual generado todavia.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'generated_at' => $snapshot->generated_at?->toIso8601String(),
            'data' => $callback($snapshot->payload_json ?? []),
        ]);
    }
}
