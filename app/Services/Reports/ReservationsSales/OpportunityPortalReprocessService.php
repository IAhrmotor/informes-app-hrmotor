<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OpportunityPortalReprocessService
{
    public const APPLY_LOCK_KEY = 'salesforce_opportunity_portal_reprocess_apply';

    private const APPLY_LOCK_TTL_SECONDS = 21600;

    private const CHUNK_SIZE = 100;

    private const MAX_CONCURRENCY_ATTEMPTS = 3;

    private const SAMPLE_LIMIT = 20;

    private const SALESFORCE_FAILURE_CODE = 7101;

    private const CONCURRENCY_FAILURE_CODE = 7102;

    private const WRITABLE_FIELDS = [
        'opportunity_source_raw',
        'opportunity_source_normalized',
        'portal_resolved',
        'portal_resolution_source',
        'portal_resolution_lead_id',
        'portal_resolution_debug',
    ];

    private const DEBUG_AUDIT_FIELDS = [
        'rawPortal',
        'normalizedPortal',
        'opportunitySourceRaw',
        'opportunitySourceNormalized',
        'selectedLeadId',
        'selectedLeadSourceNewRaw',
        'selectedLeadLegacyPortalRaw',
        'selectedLeadLegacySourceField',
        'selectedLeadPortalRaw',
        'selectedLeadEffectiveSourceField',
        'selectedLeadUsedFallback',
        'selectedLeadConflict',
        'reason',
        'lead_created_date',
    ];

    private const SELECT_FIELDS = [
        'id',
        'salesforce_id',
        'created_date',
        'portal_original',
        'opportunity_source_raw',
        'opportunity_source_normalized',
        'portal_resolved',
        'portal_resolution_source',
        'portal_resolution_lead_id',
        'portal_resolution_debug',
        'account_phone',
        'account_person_email',
        'account_company_email',
        'raw_payload',
        'updated_at',
    ];

    public function __construct(
        private readonly SalesforceOpportunitySyncService $sync,
        private readonly OpportunityPortalNormalizer $normalizer,
    ) {}

    /** @return array<string, mixed> */
    public function run(
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $apply,
        ?string $reason = null,
        ?int $limit = null,
        ?int $afterId = null,
    ): array {
        if ($to->lessThanOrEqualTo($from)) {
            throw new RuntimeException('El rango local debe cumplir from < to.');
        }

        if ($apply && mb_strlen(trim((string) $reason)) < 10) {
            throw new RuntimeException('El modo apply requiere un motivo de al menos 10 caracteres.');
        }

        if ($limit !== null && $limit < 1) {
            throw new RuntimeException('El limite debe ser un entero positivo.');
        }

        if ($afterId !== null && $afterId < 1) {
            throw new RuntimeException('El cursor after-id debe ser un ID local positivo.');
        }

        $lock = $apply ? Cache::lock(self::APPLY_LOCK_KEY, self::APPLY_LOCK_TTL_SECONDS) : null;

        if ($lock !== null && ! $lock->get()) {
            throw new RuntimeException('Ya existe otro reproceso de portales de Opportunities en modo apply.');
        }

        try {
            return $this->execute($from, $to, $apply, $reason, $limit, $afterId);
        } finally {
            $lock?->release();
        }
    }

    /** @return array<string, mixed> */
    private function execute(
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $apply,
        ?string $reason,
        ?int $limit,
        ?int $afterId,
    ): array {
        $startedAt = microtime(true);
        $stats = $this->initialStats($from, $to, $apply);
        $remaining = $limit;
        $cursor = $afterId;

        try {
            while ($remaining === null || $remaining > 0) {
                $take = min(self::CHUNK_SIZE, $remaining ?? self::CHUNK_SIZE);
                $snapshot = $this->nextRows($from, $to, $cursor, $take);

                if ($snapshot->isEmpty()) {
                    break;
                }

                try {
                    $chunk = $apply
                        ? $this->processApplyChunk($snapshot, $from, $to, (string) $reason, $stats['run_identifier'], $stats)
                        : $this->processDryRunChunk($snapshot);
                } catch (Throwable $exception) {
                    $stats['failed'] = true;
                    $errorType = 'unexpected';

                    if ($exception->getCode() === self::SALESFORCE_FAILURE_CODE) {
                        $stats['salesforce_lead_query_failures']++;
                        $stats['error'] = 'Fallo en la consulta Salesforce de Leads del chunk.';
                        $errorType = 'salesforce_lead_query';
                    } elseif ($exception->getCode() === self::CONCURRENCY_FAILURE_CODE) {
                        $stats['error'] = 'El chunk continuo cambiando durante los reintentos de concurrencia.';
                        $errorType = 'concurrency';
                    } else {
                        $stats['error'] = 'Fallo al resolver o persistir el chunk de Opportunities.';
                    }

                    if (count($stats['failed_chunks']) < self::SAMPLE_LIMIT) {
                        $stats['failed_chunks'][] = [
                            'first_local_id' => (int) $snapshot->first()->getAttribute('id'),
                            'last_local_id' => (int) $snapshot->last()->getAttribute('id'),
                            'error_type' => $errorType,
                        ];
                    }

                    break;
                }

                $this->mergeChunkStats($stats, $chunk['stats']);
                $last = $snapshot->last();
                $cursor = (int) $last->getAttribute('id');
                $stats['last_local_id_processed'] = $cursor;
                $stats['last_salesforce_id_processed'] = (string) $last->getAttribute('salesforce_id');

                if ($remaining !== null) {
                    $remaining -= $snapshot->count();
                }
            }
        } finally {
            if ($apply && $stats['rows_changed'] > 0) {
                Cache::forever(
                    'reservas_ventas_dashboard_cache_version',
                    ((int) Cache::get('reservas_ventas_dashboard_cache_version', 1)) + 1,
                );
                $stats['cache_invalidations'] = 1;
            }

            $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);
            $stats['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        }

        return $stats;
    }

    private function nextRows(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $afterId,
        int $limit,
    ): EloquentCollection {
        return SalesforceOpportunity::query()
            ->select(self::SELECT_FIELDS)
            ->where('created_date', '>=', $from)
            ->where('created_date', '<', $to)
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @return array<string, mixed> */
    private function processDryRunChunk(EloquentCollection $snapshot): array
    {
        $leadMatches = $this->leadMatches($snapshot);

        return $this->analyzeRows($snapshot, $leadMatches, false, null, null);
    }

    /**
     * @param  array<string, mixed>  $globalStats
     * @return array<string, mixed>
     */
    private function processApplyChunk(
        EloquentCollection $initialSnapshot,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $reason,
        string $runIdentifier,
        array &$globalStats,
    ): array {
        $snapshot = $initialSnapshot;
        $ids = $snapshot->modelKeys();

        for ($attempt = 1; $attempt <= self::MAX_CONCURRENCY_ATTEMPTS; $attempt++) {
            $leadMatches = $this->leadMatches($snapshot);
            $fingerprints = $snapshot->mapWithKeys(fn (SalesforceOpportunity $row): array => [
                (int) $row->getAttribute('id') => $this->resolutionFingerprint($row),
            ])->all();

            $this->beforeApplyTransaction($ids, $attempt);

            try {
                return DB::transaction(function () use ($ids, $from, $to, $fingerprints, $leadMatches, $reason, $runIdentifier): array {
                    $locked = SalesforceOpportunity::query()
                        ->select(self::SELECT_FIELDS)
                        ->whereIn('id', $ids)
                        ->where('created_date', '>=', $from)
                        ->where('created_date', '<', $to)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    $changedInputs = $locked->count() !== count($ids)
                        || $locked->contains(fn (SalesforceOpportunity $row): bool => ($fingerprints[$row->getAttribute('id')] ?? null) !== $this->resolutionFingerprint($row));

                    if ($changedInputs) {
                        throw new RuntimeException('Concurrent resolution inputs changed.', self::CONCURRENCY_FAILURE_CODE);
                    }

                    $result = $this->analyzeRows($locked, $leadMatches, true, $reason, $runIdentifier);
                    $this->bulkUpdateExisting($result['updates']);

                    if ($result['history'] !== []) {
                        DB::table('salesforce_opportunity_portal_reprocess_history')->insert($result['history']);
                    }

                    return $result;
                });
            } catch (RuntimeException $exception) {
                if ($exception->getCode() !== self::CONCURRENCY_FAILURE_CODE) {
                    throw $exception;
                }

                $globalStats['concurrency_retries']++;
                $this->appendSample($globalStats['samples']['concurrency_opportunity_ids'], $snapshot->pluck('salesforce_id')->all());

                if ($attempt === self::MAX_CONCURRENCY_ATTEMPTS) {
                    $globalStats['concurrency_failures']++;

                    throw $exception;
                }

                $snapshot = $this->rowsByIds($ids, $from, $to);
            }
        }

        throw new RuntimeException('Concurrent resolution inputs changed.', self::CONCURRENCY_FAILURE_CODE);
    }

    /**
     * Extension seam for deterministic concurrency tests.
     *
     * @param  list<int>  $ids
     */
    protected function beforeApplyTransaction(array $ids, int $attempt): void {}

    /** @param list<int> $ids */
    private function rowsByIds(array $ids, CarbonImmutable $from, CarbonImmutable $to): EloquentCollection
    {
        return SalesforceOpportunity::query()
            ->select(self::SELECT_FIELDS)
            ->whereIn('id', $ids)
            ->where('created_date', '>=', $from)
            ->where('created_date', '<', $to)
            ->orderBy('id')
            ->get();
    }

    private function leadMatches(EloquentCollection $rows): Collection
    {
        try {
            return $this->sync->relatedLeadMatchesForOpportunities(
                $rows->map(fn (SalesforceOpportunity $row): array => $this->opportunityRecord($row))->all(),
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Salesforce Lead query failed.', self::SALESFORCE_FAILURE_CODE, $exception);
        }
    }

    /**
     * @return array{updates:list<array{id:int,values:array<string,mixed>}>,history:list<array<string,mixed>>,stats:array<string,mixed>}
     */
    private function analyzeRows(
        EloquentCollection $rows,
        Collection $leadMatches,
        bool $prepareWrites,
        ?string $reason,
        ?string $runIdentifier,
    ): array {
        $updates = [];
        $history = [];
        $stats = $this->emptyChunkStats();

        foreach ($rows as $row) {
            $record = $this->opportunityRecord($row);
            $portal = $this->sync->resolvePortalForRecord($record, $leadMatches);
            $source = $this->normalizer->normalize(data_get($record, 'Fuente_de_Origen__c'));
            $candidate = [
                'opportunity_source_raw' => data_get($record, 'Fuente_de_Origen__c'),
                'opportunity_source_normalized' => $source['portal'],
                'portal_resolved' => $portal['portal'],
                'portal_resolution_source' => $portal['source'],
                'portal_resolution_lead_id' => $portal['lead_id'],
                'portal_resolution_debug' => $portal['debug'],
            ];
            $changes = $this->changes($row, $candidate);

            $stats['rows_examined']++;
            $currentSource = trim((string) $row->getAttribute('portal_resolution_source'));
            $currentSource = $currentSource === '' ? '(null)' : $currentSource;
            $stats['resolution_before'][$currentSource] = ($stats['resolution_before'][$currentSource] ?? 0) + 1;
            $stats['resolution_after'][$portal['source']] = ($stats['resolution_after'][$portal['source']] ?? 0) + 1;
            $this->incrementTransition($stats['portal_transitions'], $row->getAttribute('portal_resolved'), $candidate['portal_resolved']);
            $this->incrementTransition($stats['resolution_source_transitions'], $row->getAttribute('portal_resolution_source'), $candidate['portal_resolution_source']);

            if ($portal['source'] === 'lead') {
                if (data_get($portal, 'debug.selectedLeadEffectiveSourceField') === 'Fuente_origen__c') {
                    $stats['selected_lead_new_source']++;
                }

                if ((bool) data_get($portal, 'debug.selectedLeadUsedFallback')) {
                    $stats['selected_lead_legacy_fallback']++;
                }

                if ((bool) data_get($portal, 'debug.selectedLeadConflict')) {
                    $stats['selected_lead_conflicts']++;
                }
            }

            if ($changes === []) {
                $stats['rows_unchanged']++;

                continue;
            }

            $stats['rows_changed']++;
            foreach (array_keys($changes) as $field) {
                $stats['changes_by_field'][$field]++;
            }
            $this->appendSample($stats['samples']['changed_opportunity_ids'], [(string) $row->getAttribute('salesforce_id')]);

            if (! $prepareWrites) {
                continue;
            }

            $updates[] = [
                'id' => (int) $row->getAttribute('id'),
                'values' => array_map(fn (array $change): mixed => $change['new'], $changes),
            ];
            $history[] = $this->historyRow(
                (string) $runIdentifier,
                $row,
                (string) $reason,
                $changes,
            );
        }

        return ['updates' => $updates, 'history' => $history, 'stats' => $stats];
    }

    /** @return array<string, mixed> */
    private function opportunityRecord(SalesforceOpportunity $opportunity): array
    {
        return [
            'Id' => $opportunity->getAttribute('salesforce_id'),
            'Portal__c' => $opportunity->getAttribute('portal_original'),
            'Fuente_de_Origen__c' => $opportunity->getAttribute('opportunity_source_raw')
                ?: data_get($opportunity->getAttribute('raw_payload'), 'Fuente_de_Origen__c'),
            'Account' => [
                'Phone' => $opportunity->getAttribute('account_phone'),
                'PersonEmail' => $opportunity->getAttribute('account_person_email'),
                'AC_C_EMA_email__c' => $opportunity->getAttribute('account_company_email'),
            ],
        ];
    }

    private function resolutionFingerprint(SalesforceOpportunity $row): string
    {
        return hash('sha256', json_encode([
            'portal_original' => $row->getAttribute('portal_original'),
            'opportunity_source_raw' => $row->getAttribute('opportunity_source_raw'),
            'raw_opportunity_source' => data_get($row->getAttribute('raw_payload'), 'Fuente_de_Origen__c'),
            'account_phone' => $row->getAttribute('account_phone'),
            'account_person_email' => $row->getAttribute('account_person_email'),
            'account_company_email' => $row->getAttribute('account_company_email'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, array{previous:mixed,new:mixed,audit_previous:mixed,audit_new:mixed}> */
    private function changes(SalesforceOpportunity $row, array $candidate): array
    {
        $changes = [];

        foreach (self::WRITABLE_FIELDS as $field) {
            $previous = $row->getAttribute($field);
            $new = $candidate[$field];

            if ($this->equivalent($previous, $new)) {
                continue;
            }

            $changes[$field] = [
                'previous' => $previous,
                'new' => $new,
                'audit_previous' => $field === 'portal_resolution_debug' ? $this->auditDebug($previous) : $previous,
                'audit_new' => $field === 'portal_resolution_debug' ? $this->auditDebug($new) : $new,
            ];
        }

        return $changes;
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        return $this->normalizeComparable($left) === $this->normalizeComparable($right);
    }

    private function normalizeComparable(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeComparable($item);
        }

        return $value;
    }

    /** @param list<array{id:int,values:array<string,mixed>}> $updates */
    private function bulkUpdateExisting(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable('salesforce_opportunities');
        $idColumn = $grammar->wrap('id');
        $columns = collect($updates)
            ->flatMap(fn (array $update): array => array_keys($update['values']))
            ->unique()
            ->values();
        $bindings = [];
        $assignments = [];

        foreach ($columns as $column) {
            $wrappedColumn = $grammar->wrap($column);
            $cases = [];

            foreach ($updates as $update) {
                if (! array_key_exists($column, $update['values'])) {
                    continue;
                }

                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = $update['id'];
                $bindings[] = $this->databaseValue($update['values'][$column]);
            }

            $assignments[] = "{$wrappedColumn} = CASE {$idColumn} ".implode(' ', $cases)." ELSE {$wrappedColumn} END";
        }

        $assignments[] = $grammar->wrap('updated_at').' = ?';
        $bindings[] = now();
        $ids = array_column($updates, 'id');
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        array_push($bindings, ...$ids);

        DB::update(
            "UPDATE {$table} SET ".implode(', ', $assignments)." WHERE {$idColumn} IN ({$placeholders})",
            $bindings,
        );
    }

    private function databaseValue(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    /** @param array<string, array{previous:mixed,new:mixed,audit_previous:mixed,audit_new:mixed}> $changes */
    private function historyRow(
        string $runIdentifier,
        SalesforceOpportunity $row,
        string $reason,
        array $changes,
    ): array {
        return [
            'run_identifier' => $runIdentifier,
            'opportunity_id' => (int) $row->getAttribute('id'),
            'opportunity_salesforce_id' => (string) $row->getAttribute('salesforce_id'),
            'reason' => $reason,
            'changed_fields' => json_encode(array_keys($changes), JSON_THROW_ON_ERROR),
            'previous_values' => json_encode(
                array_map(fn (array $change): mixed => $change['audit_previous'], $changes),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'new_values' => json_encode(
                array_map(fn (array $change): mixed => $change['audit_new'], $changes),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'recorded_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function auditDebug(mixed $debug): array
    {
        $debug = is_array($debug) ? $debug : [];

        return array_intersect_key($debug, array_flip(self::DEBUG_AUDIT_FIELDS));
    }

    /** @param array<string, int> $transitions */
    private function incrementTransition(array &$transitions, mixed $from, mixed $to): void
    {
        $key = $this->transitionValue($from).' -> '.$this->transitionValue($to);
        $transitions[$key] = ($transitions[$key] ?? 0) + 1;
    }

    private function transitionValue(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '(null)' : $value;
    }

    /** @param list<string> $target @param list<string> $values */
    private function appendSample(array &$target, array $values): void
    {
        foreach ($values as $value) {
            if (count($target) >= self::SAMPLE_LIMIT) {
                break;
            }

            if (! in_array($value, $target, true)) {
                $target[] = $value;
            }
        }
    }

    /** @return array<string, mixed> */
    private function emptyChunkStats(): array
    {
        return [
            'rows_examined' => 0,
            'rows_changed' => 0,
            'rows_unchanged' => 0,
            'changes_by_field' => array_fill_keys(self::WRITABLE_FIELDS, 0),
            'resolution_before' => array_fill_keys([
                'opportunity',
                'lead',
                'opportunity_source',
                'fallback_exposicion',
                'fallback_web',
                'unclassified',
                '(null)',
            ], 0),
            'resolution_after' => array_fill_keys([
                'opportunity',
                'lead',
                'opportunity_source',
                'fallback_exposicion',
                'fallback_web',
                'unclassified',
            ], 0),
            'portal_transitions' => [],
            'resolution_source_transitions' => [],
            'selected_lead_new_source' => 0,
            'selected_lead_legacy_fallback' => 0,
            'selected_lead_conflicts' => 0,
            'samples' => ['changed_opportunity_ids' => []],
        ];
    }

    /** @param array<string, mixed> $stats @param array<string, mixed> $chunkStats */
    private function mergeChunkStats(array &$stats, array $chunkStats): void
    {
        foreach (['rows_examined', 'rows_changed', 'rows_unchanged', 'selected_lead_new_source', 'selected_lead_legacy_fallback', 'selected_lead_conflicts'] as $metric) {
            $stats[$metric] += $chunkStats[$metric];
        }

        foreach (['changes_by_field', 'resolution_before', 'resolution_after', 'portal_transitions', 'resolution_source_transitions'] as $metric) {
            foreach ($chunkStats[$metric] as $key => $count) {
                $stats[$metric][$key] = ($stats[$metric][$key] ?? 0) + $count;
            }
        }

        $this->appendSample($stats['samples']['changed_opportunity_ids'], $chunkStats['samples']['changed_opportunity_ids']);
    }

    /** @return array<string, mixed> */
    private function initialStats(CarbonImmutable $from, CarbonImmutable $to, bool $apply): array
    {
        return array_merge($this->emptyChunkStats(), [
            'mode' => $apply ? 'apply' : 'dry-run',
            'run_identifier' => (string) Str::uuid(),
            'range' => [
                'from_inclusive' => $from->toDateString(),
                'to_exclusive' => $to->toDateString(),
            ],
            'salesforce_lead_query_failures' => 0,
            'concurrency_retries' => 0,
            'concurrency_failures' => 0,
            'cache_invalidations' => 0,
            'failed_chunks' => [],
            'samples' => [
                'changed_opportunity_ids' => [],
                'concurrency_opportunity_ids' => [],
            ],
            'last_local_id_processed' => null,
            'last_salesforce_id_processed' => null,
            'failed' => false,
            'error' => null,
            'duration_seconds' => 0.0,
            'peak_memory_mb' => 0.0,
        ]);
    }
}
