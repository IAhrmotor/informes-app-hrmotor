<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionClosureEvent;
use App\Models\CommercialCommissionSnapshot;
use App\Models\ReportUser;
use App\Services\Reports\AreaManagerCommissions\AreaManagerCommissionDashboardService;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionDashboardService;
use App\Services\Reports\FinancialCommissions\FinancialCommissionDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CommercialCommissionClosureService
{
    public const REQUIRED_COMPONENTS = ['sales', 'purchases', 'cancellations', 'reviews', 'adjustments'];

    public function __construct(
        private readonly CommissionMonthResolver $monthResolver,
        private readonly CommercialCommissionDashboardService $commercials,
        private readonly CallCenterCommissionDashboardService $callCenter,
        private readonly ContactCenterCommissionDashboardService $contactCenter,
        private readonly AreaManagerCommissionDashboardService $areaManager,
        private readonly FinancialCommissionDashboardService $financials,
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
    ) {}

    /** @return array<string, mixed> */
    public function status(?string $month): array
    {
        $selected = $this->monthResolver->resolve($month);
        $monthKey = $selected->format('Y-m');
        $closure = CommercialCommissionClosure::query()
            ->with(['approver:id,name,email', 'reopener:id,name,email'])
            ->where('month', $monthKey)
            ->first();
        $defaultStatus = $selected->equalTo(CarbonImmutable::now(config('app.timezone'))->startOfMonth())
            ? CommercialCommissionClosure::STATUS_PROVISIONAL
            : CommercialCommissionClosure::STATUS_PENDING_APPROVAL;

        return [
            'month' => $monthKey,
            'status' => $closure?->status ?? $defaultStatus,
            'is_current_month' => $selected->isCurrentMonth(),
            'component_statuses' => $closure?->component_statuses ?? array_fill_keys(self::REQUIRED_COMPONENTS, false),
            'issues' => $closure?->issues ?? [],
            'data_cutoff_at' => $closure?->data_cutoff_at?->toIso8601String(),
            'formula_version' => $closure?->formula_version ?? CommercialCommissionFormulaConfigService::VERSION,
            'approved_by' => $closure?->approver ? [
                'id' => $closure->approver->id,
                'name' => $closure->approver->name,
                'email' => $closure->approver->email,
            ] : null,
            'approved_at' => $closure?->approved_at?->toIso8601String(),
            'reopened_by' => $closure?->reopener ? [
                'id' => $closure->reopener->id,
                'name' => $closure->reopener->name,
                'email' => $closure->reopener->email,
            ] : null,
            'reopened_at' => $closure?->reopened_at?->toIso8601String(),
            'reopen_reason' => $closure?->reopen_reason,
            'snapshot_version' => (int) ($closure?->snapshot_version ?? 0),
        ];
    }

    /** @param array<string, bool> $components */
    public function prepare(string $month, array $components, ReportUser $user): CommercialCommissionClosure
    {
        $selected = $this->monthResolver->resolve($month);
        $this->assertNaturalMonthFinished($selected);
        $components = $this->normalizeComponents($components);
        $snapshot = $this->buildSnapshotPayload($selected->format('Y-m'));
        $issues = $snapshot['issues'];

        return DB::transaction(function () use ($selected, $components, $issues, $snapshot, $user): CommercialCommissionClosure {
            $monthKey = $selected->format('Y-m');
            $closure = CommercialCommissionClosure::query()->lockForUpdate()->firstOrNew(['month' => $monthKey]);
            if ($closure->exists && $closure->status === CommercialCommissionClosure::STATUS_DEFINITIVE) {
                throw ValidationException::withMessages(['month' => 'El mes es definitivo. Debe reabrirse antes de preparar un nuevo cierre.']);
            }
            $from = $closure->exists ? $closure->status : null;
            $closure->fill([
                'status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
                'component_statuses' => $components,
                'issues' => $issues,
                'data_cutoff_at' => $snapshot['data_cutoff_at'],
                'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
            ])->save();
            $this->event($closure, 'prepared', $from, $closure->status, $user, null, [
                'component_statuses' => $components,
                'issues' => $issues,
            ]);

            return $closure->fresh();
        });
    }

    public function approve(string $month, ReportUser $user): CommercialCommissionClosure
    {
        $selected = $this->monthResolver->resolve($month);
        $this->assertNaturalMonthFinished($selected);
        $monthKey = $selected->format('Y-m');
        $payload = $this->buildSnapshotPayload($monthKey);

        return DB::transaction(function () use ($monthKey, $payload, $user): CommercialCommissionClosure {
            $closure = CommercialCommissionClosure::query()->where('month', $monthKey)->lockForUpdate()->first();
            if (! $closure || $closure->status !== CommercialCommissionClosure::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages(['month' => 'El mes debe estar pendiente de aprobación antes de hacerlo definitivo.']);
            }
            $missingComponents = collect(self::REQUIRED_COMPONENTS)
                ->reject(fn (string $component): bool => (bool) data_get($closure->component_statuses, $component, false))
                ->values();
            if ($missingComponents->isNotEmpty()) {
                throw ValidationException::withMessages(['components' => 'Faltan componentes confirmados: '.$missingComponents->implode(', ').'.']);
            }
            if ($payload['issues'] !== []) {
                $closure->update(['issues' => $payload['issues']]);
                throw ValidationException::withMessages(['issues' => 'Existen incidencias relevantes pendientes: '.implode(' | ', $payload['issues'])]);
            }

            $version = (int) $closure->snapshot_version + 1;
            CommercialCommissionSnapshot::query()->create([
                'closure_id' => $closure->id,
                'month' => $monthKey,
                'version' => $version,
                'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
                'data_cutoff_at' => $payload['data_cutoff_at'],
                'payload' => $payload['dashboards'],
                'source_state' => $payload['source_state'],
                'created_by' => $user->id,
                'created_at' => now(),
            ]);
            $from = $closure->status;
            $closure->update([
                'status' => CommercialCommissionClosure::STATUS_DEFINITIVE,
                'issues' => [],
                'data_cutoff_at' => $payload['data_cutoff_at'],
                'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'reopened_by' => null,
                'reopened_at' => null,
                'reopen_reason' => null,
                'snapshot_version' => $version,
            ]);
            $this->event($closure, 'approved', $from, $closure->status, $user, null, [
                'snapshot_version' => $version,
                'data_cutoff_at' => $payload['data_cutoff_at'],
                'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
            ]);

            return $closure->fresh();
        });
    }

    public function reopen(string $month, string $reason, ReportUser $user): CommercialCommissionClosure
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'El motivo de reapertura es obligatorio y debe ser suficientemente descriptivo.']);
        }

        return DB::transaction(function () use ($month, $reason, $user): CommercialCommissionClosure {
            $monthKey = $this->monthResolver->resolve($month)->format('Y-m');
            $closure = CommercialCommissionClosure::query()->where('month', $monthKey)->lockForUpdate()->first();
            if (! $closure || $closure->status !== CommercialCommissionClosure::STATUS_DEFINITIVE) {
                throw ValidationException::withMessages(['month' => 'Solo se puede reabrir un mes definitivo.']);
            }
            $from = $closure->status;
            $closure->update([
                'status' => CommercialCommissionClosure::STATUS_REOPENED,
                'reopened_by' => $user->id,
                'reopened_at' => now(),
                'reopen_reason' => $reason,
            ]);
            $this->event($closure, 'reopened', $from, $closure->status, $user, $reason);

            return $closure->fresh();
        });
    }

    /** @return array<string, mixed>|null */
    public function definitiveSnapshot(?string $month): ?array
    {
        $monthKey = $this->monthResolver->resolve($month)->format('Y-m');
        $closure = CommercialCommissionClosure::query()
            ->where('month', $monthKey)
            ->where('status', CommercialCommissionClosure::STATUS_DEFINITIVE)
            ->first();
        if (! $closure) {
            return null;
        }

        return $closure->snapshots()->where('version', $closure->snapshot_version)->first()?->payload;
    }

    public function nextOpenMonth(?string $requested = null): string
    {
        $month = $this->monthResolver->resolve($requested);
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $status = CommercialCommissionClosure::query()->where('month', $month->format('Y-m'))->value('status');
            if ($status !== CommercialCommissionClosure::STATUS_DEFINITIVE) {
                return $month->format('Y-m');
            }
            $month = $month->addMonthNoOverflow();
        }

        throw ValidationException::withMessages(['application_month' => 'No se encontró un mes económico abierto.']);
    }

    /** @return array{dashboards: array<string, mixed>, issues: array<int, string>, data_cutoff_at: CarbonImmutable, source_state: array<string, mixed>} */
    private function buildSnapshotPayload(string $month): array
    {
        $capturedAt = CarbonImmutable::now(config('app.timezone'));
        $commercial = $this->commercials->build($month, true, true, true);
        $callCenter = $this->callCenter->build($month, includeDetails: true);
        $contactCenter = $this->contactCenter->build($month, includeDetails: true);
        $areaManager = $this->areaManager->build($month);
        $areaManagersByZone = ReportUser::query()
            ->whereIn('role', [ReportUser::ROLE_AREA_MANAGER, ReportUser::LEGACY_ROLE_AREA_MANAGER_OWN_AREA])
            ->whereNotNull('area_zone')
            ->pluck('area_zone')
            ->unique()
            ->mapWithKeys(function (string $zoneKey) use ($month): array {
                $zoneLabel = ReportUser::areaZoneLabel($zoneKey);

                return $zoneLabel ? [$zoneKey => $this->areaManager->build($month, $zoneLabel)] : [];
            })
            ->all();
        $financials = $this->financials->build($month);
        $dashboards = [
            'commercials' => $commercial,
            'call_center' => $callCenter,
            'contact_center' => $contactCenter,
            'area_manager' => $areaManager,
            'area_manager_by_zone' => $areaManagersByZone,
            'financials' => $financials,
            'formula_settings' => $this->formulaConfig->forMonth($month),
            'review_audit' => $this->commercials->reviewAudit($month),
        ];
        $issues = collect($dashboards)
            ->flatMap(fn (array $dashboard): array => $dashboard['issues'] ?? [])
            ->map(fn (mixed $issue): string => trim((string) $issue))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $sourceState = $this->sourceState();
        $cutoff = $capturedAt;

        $sourceState['_snapshot'] = [
            'count' => 1,
            'updated_at' => $capturedAt->toIso8601String(),
        ];

        return [
            'dashboards' => $dashboards,
            'issues' => $issues,
            'data_cutoff_at' => $cutoff,
            'source_state' => $sourceState,
        ];
    }

    /** @return array<string, array{count: int, updated_at: ?string}> */
    private function sourceState(): array
    {
        $tables = [
            'sales' => 'salesforce_opportunities',
            'leads' => 'salesforce_leads',
            'reviews' => 'salesforce_reviews',
            'appraisals' => 'salesforce_tasaciones',
            'cancellations' => 'commercial_financing_penalties',
            'adjustments' => 'commercial_commission_adjustments',
        ];

        return collect($tables)->mapWithKeys(function (string $table, string $key): array {
            if (! Schema::hasTable($table)) {
                return [$key => ['count' => 0, 'updated_at' => null]];
            }
            $updatedColumn = Schema::hasColumn($table, 'updated_at') ? 'updated_at' : 'created_at';

            return [$key => [
                'count' => DB::table($table)->count(),
                'updated_at' => DB::table($table)->max($updatedColumn),
            ]];
        })->all();
    }

    /** @param array<string, bool> $components @return array<string, bool> */
    private function normalizeComponents(array $components): array
    {
        return collect(self::REQUIRED_COMPONENTS)
            ->mapWithKeys(fn (string $key): array => [$key => (bool) ($components[$key] ?? false)])
            ->all();
    }

    private function assertNaturalMonthFinished(CarbonImmutable $month): void
    {
        if ($month->greaterThanOrEqualTo(CarbonImmutable::now(config('app.timezone'))->startOfMonth())) {
            throw ValidationException::withMessages(['month' => 'El mes natural debe haber terminado antes de poder cerrarse.']);
        }
    }

    /** @param array<string, mixed> $context */
    private function event(
        CommercialCommissionClosure $closure,
        string $action,
        ?string $from,
        string $to,
        ReportUser $user,
        ?string $reason = null,
        array $context = [],
    ): void {
        CommercialCommissionClosureEvent::query()->create([
            'closure_id' => $closure->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'report_user_id' => $user->id,
            'reason' => $reason,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
