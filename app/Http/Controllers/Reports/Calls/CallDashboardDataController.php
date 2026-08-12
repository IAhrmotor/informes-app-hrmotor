<?php

namespace App\Http\Controllers\Reports\Calls;

use App\Http\Controllers\Controller;
use App\Services\Reports\Calls\CallDashboardDatasetService;
use App\Support\CsvValueSerializer;
use App\Support\ReportServerTiming;
use App\Support\ReportUserAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallDashboardDataController extends Controller
{
    public function __construct(
        private readonly CallDashboardDatasetService $dataset,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        return $this->timedJson($request, fn (?ReportServerTiming $timing): array => $this->dataset->summary($request, $timing));
    }

    public function agents(Request $request): JsonResponse
    {
        return $this->timedJson($request, fn (?ReportServerTiming $timing): array => $this->dataset->agentRows($request, $timing));
    }

    public function delegations(Request $request): JsonResponse
    {
        return $this->timedJson($request, fn (?ReportServerTiming $timing): array => $this->dataset->delegationRows($request, $timing));
    }

    public function portals(Request $request): JsonResponse
    {
        return response()->json($this->dataset->portalRows($request));
    }

    private function timedJson(Request $request, callable $callback): JsonResponse
    {
        $timing = ReportServerTiming::forRequest($request);
        $response = response()->json($callback($timing));

        if ($timing !== null && $timing->headerValue() !== '') {
            $response->headers->set('Server-Timing', $timing->headerValue());
        }

        return $response;
    }

    public function audit(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'calls'), 403);

        $items = $this->dataset->auditRows($request);

        return response()->json(['ok' => true, 'total' => count($items), 'items' => $items]);
    }

    public function exportAuditCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'calls'), 403);
        $headers = $this->dataset->auditColumns();

        return response()->streamDownload(function () use ($request, $headers): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            foreach ($this->dataset->auditRowsLazy($request) as $row) {
                fputcsv($output, array_map(
                    fn (string $column) => CsvValueSerializer::serialize($row[$column] ?? null),
                    $headers
                ));
            }
            fclose($output);
        }, 'llamadas-conciliacion.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
