<?php

namespace App\Http\Controllers\Reports\Operations;

use App\Http\Controllers\Controller;
use App\Models\OperationalAlert;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationalAlertController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['open', 'resolved'])],
            'severity' => ['nullable', Rule::in(['critical', 'high', 'medium', 'low'])],
            'type' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:120'],
        ]);

        $query = OperationalAlert::query()
            ->when($filters['state'] ?? null, fn ($builder, string $state) => $builder->where('state', $state))
            ->when($filters['severity'] ?? null, fn ($builder, string $severity) => $builder->where('severity', $severity))
            ->when($filters['type'] ?? null, fn ($builder, string $type) => $builder->where('type', $type))
            ->when($filters['source'] ?? null, fn ($builder, string $source) => $builder->where('source', $source));

        return view('reports.operations.alerts', [
            'alerts' => $query
                ->orderByRaw("CASE WHEN state = 'open' THEN 0 ELSE 1 END")
                ->orderByDesc('last_detected_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'openCount' => OperationalAlert::query()->where('state', OperationalAlert::STATE_OPEN)->count(),
            'resolvedCount' => OperationalAlert::query()->where('state', OperationalAlert::STATE_RESOLVED)->count(),
            'types' => OperationalAlert::query()->distinct()->orderBy('type')->pluck('type'),
            'sources' => OperationalAlert::query()->distinct()->orderBy('source')->pluck('source'),
            'filters' => $filters,
        ]);
    }
}
