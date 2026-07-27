<?php

namespace App\Http\Controllers\Reports\CommercialCommissions;

use App\Http\Controllers\Controller;
use App\Models\CommercialFinancingPenalty;
use App\Models\CommercialFinancingPenaltyImport;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\CommercialFinancingPenaltyImportService;
use App\Services\Reports\CommercialCommissions\Import\CommercialFinancingPenaltyImportException;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class CommercialFinancingPenaltyImportController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ReportUserAccess::canManageReportUsers($request)) {
            return redirect()->route('reports.index');
        }

        if (! Schema::hasTable('commercial_financing_penalty_imports') || ! Schema::hasTable('commercial_financing_penalties')) {
            return view('reports.commercial-commissions.financing-penalties', [
                'reportUserRole' => ReportUserAccess::role($request),
                'migrationPending' => true,
                'recentImports' => collect(),
                'activePenalties' => collect(),
                'unmatchedPenalties' => collect(),
            ]);
        }

        $activePenalties = CommercialFinancingPenalty::query()
            ->where('is_active', true)
            ->get();
        $salesforceEmails = SalesforceUser::query()
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn (string $email): string => Str::lower(trim($email)))
            ->flip();

        return view('reports.commercial-commissions.financing-penalties', [
            'reportUserRole' => ReportUserAccess::role($request),
            'recentImports' => CommercialFinancingPenaltyImport::query()
                ->latest()
                ->limit(12)
                ->get(),
            'activePenalties' => $activePenalties
                ->groupBy(fn (CommercialFinancingPenalty $penalty): string => $penalty->commission_month->toDateString())
                ->sortKeysDesc()
                ->map(function ($penalties): object {
                    return (object) [
                        'commission_month' => $penalties->first()->commission_month,
                        'rows_count' => $penalties->count(),
                        'total_amount' => round((float) $penalties->sum('amount'), 2),
                    ];
                })
                ->values(),
            'unmatchedPenalties' => $activePenalties
                ->filter(fn (CommercialFinancingPenalty $penalty): bool => ! $salesforceEmails->has(Str::lower(trim($penalty->commercial_email))))
                ->sortByDesc('created_at')
                ->limit(20)
                ->values(),
        ]);
    }

    public function store(Request $request, CommercialFinancingPenaltyImportService $importer): RedirectResponse
    {
        if (! ReportUserAccess::canManageReportUsers($request)) {
            return redirect()->route('reports.index');
        }

        if (! Schema::hasTable('commercial_financing_penalty_imports') || ! Schema::hasTable('commercial_financing_penalties')) {
            return back()->withErrors(['file' => 'Faltan las tablas de penalizaciones. Ejecuta php artisan migrate antes de cargar un archivo.']);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ], [
            'file.mimes' => 'El archivo debe ser un Excel .xlsx.',
        ]);

        try {
            $result = $importer->import(
                $data['file'],
                ReportUserAccess::current($request)['id'] ?? null,
            );
        } catch (CommercialFinancingPenaltyImportException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return redirect()
            ->route('reports.commission-penalties.index')
            ->with('status', sprintf(
                'Importacion completada: %d filas activas para %s. Las cargas anteriores de esos meses quedaron sustituidas.',
                $result['import']->rows_imported,
                implode(', ', $result['months'])
            ));
    }
}
