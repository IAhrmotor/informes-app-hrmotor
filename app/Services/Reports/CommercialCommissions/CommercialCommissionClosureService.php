<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionClosureEvent;
use App\Models\CommercialCommissionSnapshot;
use App\Models\ReportUser;
use App\Services\Reports\AreaManagerCommissions\AreaManagerCommissionDashboardService;
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
        private readonly AreaManagerCommissionDashboardService $areaManager,
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
    ) {}

    /** @return array<string, mixed> */
    public function status(?string $month, string $scope = CommercialCommissionClosure::SCOPE_COMMERCIALS): array
    {
        $this->assertClosableScope($scope);
        $selected = $this->monthResolver->resolve($month);
        $monthKey = $selected->format('Y-m');
        $closure = CommercialCommissionClosure::query()
            ->with(['approver:id,name', 'reopener:id,name'])
            ->where(['month' => $monthKey, 'closure_scope' => $scope])
            ->first();

        return $this->statusPayload($selected, $scope, $closure);
    }

    /** @return array<string, array<string, mixed>> */
    public function statuses(?string $month): array
    {
        return collect(CommercialCommissionClosure::CLOSABLE_SCOPES)
            ->mapWithKeys(fn (string $scope): array => [$scope => $this->status($month, $scope)])
            ->all();
    }

    /** @param array<string, bool> $components */
    public function prepare(string $month, string $scope, array $components, ReportUser $user): CommercialCommissionClosure
    {
        $this->assertClosableScope($scope);
        $selected = $this->monthResolver->resolve($month);
        $this->assertNaturalMonthFinished($selected);
        $components = $this->normalizeComponents($components);
        $snapshot = $this->buildSnapshotPayload($selected->format('Y-m'), $scope);

        return DB::transaction(function () use ($selected, $scope, $components, $snapshot, $user): CommercialCommissionClosure {
            $monthKey = $selected->format('Y-m');
            $closure = CommercialCommissionClosure::query()
                ->where(['month' => $monthKey, 'closure_scope' => $scope])
                ->lockForUpdate()
                ->first();
            if ($closure?->status === CommercialCommissionClosure::STATUS_DEFINITIVE) {
                throw ValidationException::withMessages(['month' => 'El bloque es definitivo. Debe reabrirse antes de preparar un nuevo cierre.']);
            }
            if ($closure === null) {
                $closure = new CommercialCommissionClosure(['month' => $monthKey, 'closure_scope' => $scope]);
            }
            $from = $closure->exists ? $closure->status : null;
            $closure->fill([
                'status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
                'component_statuses' => $components,
                'issues' => $snapshot['issues'],
                'data_cutoff_at' => $snapshot['data_cutoff_at'],
                'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
            ])->save();
            $this->event($closure, 'prepared', $from, $closure->status, $user, null, ['closure_scope' => $scope, 'issues' => $snapshot['issues']]);

            return $closure->fresh();
        });
    }

    public function approve(string $month, string $scope, ReportUser $user): CommercialCommissionClosure
    {
        $this->assertClosableScope($scope);
        $selected = $this->monthResolver->resolve($month);
        $this->assertNaturalMonthFinished($selected);
        $monthKey = $selected->format('Y-m');
        $payload = $this->buildSnapshotPayload($monthKey, $scope);

        return DB::transaction(function () use ($monthKey, $scope, $payload, $user): CommercialCommissionClosure {
            $closure = CommercialCommissionClosure::query()->where(['month' => $monthKey, 'closure_scope' => $scope])->lockForUpdate()->first();
            if (! $closure || $closure->status !== CommercialCommissionClosure::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages(['month' => 'El bloque debe estar pendiente de aprobación antes de hacerlo definitivo.']);
            }
            if (collect(self::REQUIRED_COMPONENTS)->contains(fn (string $component): bool => ! data_get($closure->component_statuses, $component, false))) {
                throw ValidationException::withMessages(['components' => 'Faltan componentes confirmados para este bloque.']);
            }
            if ($payload['issues'] !== []) {
                $closure->update(['issues' => $payload['issues']]);
                throw ValidationException::withMessages(['issues' => 'Existen incidencias relevantes del bloque: '.implode(' | ', $payload['issues'])]);
            }

            $version = (int) $closure->snapshot_version + 1;
            CommercialCommissionSnapshot::query()->create([
                'closure_id' => $closure->id, 'month' => $monthKey, 'version' => $version,
                'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
                'data_cutoff_at' => $payload['data_cutoff_at'], 'payload' => $payload['dashboard'],
                'source_state' => $payload['source_state'], 'created_by' => $user->id, 'created_at' => now(),
            ]);
            $from = $closure->status;
            $closure->update([
                'status' => CommercialCommissionClosure::STATUS_DEFINITIVE, 'issues' => [],
                'data_cutoff_at' => $payload['data_cutoff_at'], 'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
                'approved_by' => $user->id, 'approved_at' => now(), 'reopened_by' => null, 'reopened_at' => null,
                'reopen_reason' => null, 'snapshot_version' => $version,
            ]);
            $this->event($closure, 'approved', $from, $closure->status, $user, null, ['closure_scope' => $scope, 'snapshot_version' => $version]);

            return $closure->fresh();
        });
    }

    public function reopen(string $month, string $scope, string $reason, ReportUser $user): CommercialCommissionClosure
    {
        $this->assertClosableScope($scope);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'El motivo de reapertura es obligatorio y debe ser suficientemente descriptivo.']);
        }

        return DB::transaction(function () use ($month, $scope, $reason, $user): CommercialCommissionClosure {
            $monthKey = $this->monthResolver->resolve($month)->format('Y-m');
            $closure = CommercialCommissionClosure::query()->where(['month' => $monthKey, 'closure_scope' => $scope])->lockForUpdate()->first();
            if (! $closure || $closure->status !== CommercialCommissionClosure::STATUS_DEFINITIVE) {
                throw ValidationException::withMessages(['month' => 'Solo se puede reabrir un bloque definitivo.']);
            }
            $from = $closure->status;
            $closure->update(['status' => CommercialCommissionClosure::STATUS_REOPENED, 'reopened_by' => $user->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);
            $this->event($closure, 'reopened', $from, $closure->status, $user, $reason, ['closure_scope' => $scope, 'snapshot_version' => $closure->snapshot_version]);

            return $closure->fresh();
        });
    }

    /** @return array<string, mixed>|null */
    public function definitiveSnapshot(?string $month, string $scope = CommercialCommissionClosure::SCOPE_COMMERCIALS): ?array
    {
        $this->assertClosableScope($scope);
        $closure = CommercialCommissionClosure::query()
            ->where(['month' => $this->monthResolver->resolve($month)->format('Y-m'), 'closure_scope' => $scope, 'status' => CommercialCommissionClosure::STATUS_DEFINITIVE])
            ->first();

        return $closure?->snapshots()->where('version', $closure->snapshot_version)->first()?->payload;
    }

    public function nextOpenMonth(?string $requested = null, string $scope = CommercialCommissionClosure::SCOPE_COMMERCIALS): string
    {
        $this->assertClosableScope($scope);
        $month = $this->monthResolver->resolve($requested);
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $status = CommercialCommissionClosure::query()->where(['month' => $month->format('Y-m'), 'closure_scope' => $scope])->value('status');
            if ($status !== CommercialCommissionClosure::STATUS_DEFINITIVE) {
                return $month->format('Y-m');
            }
            $month = $month->addMonthNoOverflow();
        }
        throw ValidationException::withMessages(['application_month' => 'No se encontró un mes económico abierto.']);
    }

    /** @return array{dashboard: array<string, mixed>, issues: array<int, string>, data_cutoff_at: CarbonImmutable, source_state: array<string, mixed>} */
    private function buildSnapshotPayload(string $month, string $scope): array
    {
        $capturedAt = CarbonImmutable::now(config('app.timezone'));
        $dashboard = match ($scope) {
            CommercialCommissionClosure::SCOPE_COMMERCIALS => [
                'commercials' => $this->commercials->build($month, true, false, true),
                'formula_settings' => $this->formulaConfig->forMonth($month),
                'review_audit' => $this->commercials->reviewAudit($month),
            ],
            CommercialCommissionClosure::SCOPE_DELEGATIONS => [
                'delegations' => $this->commercials->build($month, false, true, false),
                'formula_settings' => $this->formulaConfig->forMonth($month),
            ],
            CommercialCommissionClosure::SCOPE_AREA_MANAGER => $this->areaManagerSnapshot($month),
        };
        $issues = collect($dashboard)
            ->flatMap(fn (mixed $value): array => is_array($value) ? ($value['issues'] ?? []) : [])
            ->map(fn (mixed $issue): string => trim((string) $issue))
            ->filter()
            ->reject(fn (string $issue): bool => str_contains(mb_strtolower($issue), 'reseña'))
            ->unique()->values()->all();

        return ['dashboard' => $dashboard, 'issues' => $issues, 'data_cutoff_at' => $capturedAt, 'source_state' => $this->sourceState($scope, $capturedAt)];
    }

    /** @return array<string, mixed> */
    private function areaManagerSnapshot(string $month): array
    {
        $byZone = ReportUser::query()->whereIn('role', [ReportUser::ROLE_AREA_MANAGER, ReportUser::LEGACY_ROLE_AREA_MANAGER_OWN_AREA])
            ->whereNotNull('area_zone')->pluck('area_zone')->unique()
            ->mapWithKeys(function (string $zoneKey) use ($month): array {
                $label = ReportUser::areaZoneLabel($zoneKey);

                return $label ? [$zoneKey => $this->areaManager->build($month, $label)] : [];
            })->all();

        return ['area_manager' => $this->areaManager->build($month), 'area_manager_by_zone' => $byZone, 'formula_settings' => $this->formulaConfig->forMonth($month)];
    }

    /** @return array<string, array{count: int, updated_at: ?string}> */
    private function sourceState(string $scope, CarbonImmutable $capturedAt): array
    {
        $tables = match ($scope) {
            CommercialCommissionClosure::SCOPE_COMMERCIALS => ['sales' => 'salesforce_opportunities', 'reviews' => 'salesforce_reviews', 'adjustments' => 'commercial_commission_adjustments'],
            CommercialCommissionClosure::SCOPE_DELEGATIONS => ['sales' => 'salesforce_opportunities'],
            default => ['sales' => 'salesforce_opportunities'],
        };

        return collect($tables)->mapWithKeys(function (string $table, string $key): array {
            if (! Schema::hasTable($table)) {
                return [$key => ['count' => 0, 'updated_at' => null]];
            }
            $column = Schema::hasColumn($table, 'updated_at') ? 'updated_at' : 'created_at';

            return [$key => ['count' => DB::table($table)->count(), 'updated_at' => DB::table($table)->max($column)]];
        })->put('_snapshot', ['count' => 1, 'updated_at' => $capturedAt->toIso8601String()])->all();
    }

    /** @return array<string, mixed> */
    private function statusPayload(CarbonImmutable $selected, string $scope, ?CommercialCommissionClosure $closure): array
    {
        $default = $selected->isCurrentMonth() ? CommercialCommissionClosure::STATUS_PROVISIONAL : CommercialCommissionClosure::STATUS_PENDING_APPROVAL;

        return [
            'month' => $selected->format('Y-m'), 'closure_scope' => $scope, 'status' => $closure?->status ?? $default,
            'is_current_month' => $selected->isCurrentMonth(), 'component_statuses' => $closure?->component_statuses ?? array_fill_keys(self::REQUIRED_COMPONENTS, false),
            'issues' => $closure?->issues ?? [], 'data_cutoff_at' => $closure?->data_cutoff_at?->toIso8601String(),
            'formula_version' => $closure?->formula_version ?? CommercialCommissionFormulaConfigService::VERSION,
            'approved_by' => $closure?->approver ? ['id' => $closure->approver->id, 'name' => $closure->approver->name] : null,
            'approved_at' => $closure?->approved_at?->toIso8601String(), 'reopened_by' => $closure?->reopener ? ['id' => $closure->reopener->id, 'name' => $closure->reopener->name] : null,
            'reopened_at' => $closure?->reopened_at?->toIso8601String(), 'reopen_reason' => $closure?->reopen_reason,
            'snapshot_version' => (int) ($closure?->snapshot_version ?? 0),
        ];
    }

    /** @param array<string, bool> $components @return array<string, bool> */
    private function normalizeComponents(array $components): array
    {
        return collect(self::REQUIRED_COMPONENTS)->mapWithKeys(fn (string $key): array => [$key => (bool) ($components[$key] ?? false)])->all();
    }

    private function assertClosableScope(string $scope): void
    {
        if (! in_array($scope, CommercialCommissionClosure::CLOSABLE_SCOPES, true)) {
            throw ValidationException::withMessages(['closure_scope' => 'El bloque de cierre no es válido.']);
        }
    }

    private function assertNaturalMonthFinished(CarbonImmutable $month): void
    {
        if ($month->greaterThanOrEqualTo(CarbonImmutable::now(config('app.timezone'))->startOfMonth())) {
            throw ValidationException::withMessages(['month' => 'El mes natural debe haber terminado antes de poder cerrarse.']);
        }
    }

    /** @param array<string, mixed> $context */
    private function event(CommercialCommissionClosure $closure, string $action, ?string $from, string $to, ReportUser $user, ?string $reason = null, array $context = []): void
    {
        CommercialCommissionClosureEvent::query()->create(['closure_id' => $closure->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'report_user_id' => $user->id, 'reason' => $reason, 'context' => $context, 'created_at' => now()]);
    }
}
