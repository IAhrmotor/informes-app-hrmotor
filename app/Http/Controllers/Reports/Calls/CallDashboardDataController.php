<?php

namespace App\Http\Controllers\Reports\Calls;

use App\Http\Controllers\Controller;
use App\Services\Reports\Calls\CallDashboardDatasetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\ReportUserAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallDashboardDataController extends Controller
{
    public function __construct(
        private readonly CallDashboardDatasetService $dataset,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->dataset->summary($request));
    }

    public function agents(Request $request): JsonResponse
    {
        return response()->json($this->dataset->agentRows($request));
    }

    public function delegations(Request $request): JsonResponse
    {
        return response()->json($this->dataset->delegationRows($request));
    }

    public function portals(Request $request): JsonResponse
    {
        return response()->json($this->dataset->portalRows($request));
    }

    public function audit(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canAudit($request), 403);

        $items = $this->dataset->auditRows($request);

        return response()->json(['ok' => true, 'total' => count($items), 'items' => $items]);
    }

    public function exportAuditCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canAudit($request), 403);
        $rows = $this->dataset->auditRows($request);
        $headers = $rows === [] ? ['Task ID'] : array_keys($rows[0]);

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            foreach ($rows as $row) {
                fputcsv($output, array_map(fn ($value) => is_bool($value) ? ($value ? 'Si' : 'No') : $value, $row));
            }
            fclose($output);
        }, 'llamadas-conciliacion.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
