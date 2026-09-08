<?php

namespace App\Services\Salesforce;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityPresenceReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesforceOpportunityPresenceReconciliationService
{
    public const APPLY_LOCK_KEY = 'salesforce_opportunity_presence_reconciliation_apply';

    private const APPLY_LOCK_TTL_SECONDS = 21600;

    private const CHUNK_SIZE = 100;

    private const SAMPLE_LIMIT = 20;

    private const SELECT_FIELDS = [
        'id',
        'salesforce_id',
        'is_deleted',
        'salesforce_deleted_at',
        'deletion_detection_source',
        'updated_at',
    ];

    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    /** @return array<string, mixed> */
    public function run(bool $apply, ?string $reason = null, ?int $limit = null, ?int $afterId = null): array
    {
        $reasonLength = mb_strlen(trim((string) $reason));
        if ($apply && ($reasonLength < 10 || $reasonLength > 500)) {
            throw new RuntimeException('El modo apply requiere un motivo de entre 10 y 500 caracteres.');
        }

        if ($limit !== null && $limit < 1) {
            throw new RuntimeException('El limite debe ser un entero positivo.');
        }

        if ($afterId !== null && $afterId < 1) {
            throw new RuntimeException('El cursor after-id debe ser un ID local positivo.');
        }

        $lock = $apply ? Cache::lock(self::APPLY_LOCK_KEY, self::APPLY_LOCK_TTL_SECONDS) : null;
        if ($lock !== null && ! $lock->get()) {
            throw new RuntimeException('Ya existe otra reconciliacion de presencia de Opportunities en modo apply.');
        }

        $runIdentifier = (string) Str::uuid();
        $auditRun = null;

        try {
            if ($apply) {
                $auditRun = SalesforceOpportunityPresenceReconciliationRun::query()->create([
                    'run_identifier' => $runIdentifier,
                    'reason' => trim((string) $reason),
                    'status' => 'running',
                    'started_at' => now(),
                ]);
            }

            $stats = $this->execute($apply, $limit, $afterId, $runIdentifier);
            if ($apply && $stats['rows_changed'] > 0) {
                $this->invalidateAffectedCaches();
            }
            $auditRun?->update([
                'status' => $stats['failed'] ? 'failed' : 'completed',
                'finished_at' => now(),
                'rows_examined' => $stats['rows_examined'],
                'rows_changed' => $stats['rows_changed'],
            ]);

            return $stats;
        } catch (Throwable $exception) {
            $auditRun?->update(['status' => 'failed', 'finished_at' => now()]);

            throw $exception;
        } finally {
            $lock?->release();
        }
    }

    /** @return array<string, mixed> */
    private function execute(bool $apply, ?int $limit, ?int $afterId, string $runIdentifier): array
    {
        $startedAt = microtime(true);
        $stats = $this->initialStats($apply, $runIdentifier);
        $remaining = $limit;
        $cursor = $afterId;

        while ($remaining === null || $remaining > 0) {
            $take = min(self::CHUNK_SIZE, $remaining ?? self::CHUNK_SIZE);
            $snapshot = $this->nextRows($cursor, $take);
            if ($snapshot->isEmpty()) {
                break;
            }

            $chunkStats = $this->emptyStats();
            $chunkStats['rows_examined'] = $snapshot->count();
            $validRows = $snapshot->filter(fn (SalesforceOpportunity $row): bool => $this->isValidOpportunityId((string) $row->salesforce_id));
            $invalidIds = $snapshot->reject(fn (SalesforceOpportunity $row): bool => $this->isValidOpportunityId((string) $row->salesforce_id))
                ->pluck('salesforce_id')->map(fn (mixed $id): string => (string) $id)->all();
            $chunkStats['ids_valid'] = $validRows->count();
            $chunkStats['ids_invalid'] = count($invalidIds);
            $this->appendSamples($chunkStats['samples']['invalid_salesforce_ids'], $invalidIds);

            try {
                $records = $this->querySalesforce($validRows, $chunkStats);
                $rowStats = $apply
                    ? $this->processApplyChunk($snapshot, $records)
                    : $this->processDryRunChunk($snapshot, $records);
                $this->mergeStats($chunkStats, $rowStats);
            } catch (Throwable) {
                $chunkStats['failed'] = true;
                $chunkStats['error'] = 'presence_chunk_failed';
                $this->mergeStats($stats, $chunkStats);
                $stats['failed'] = true;
                $stats['error'] = 'presence_chunk_failed';

                break;
            }

            $this->mergeStats($stats, $chunkStats);
            $cursor = (int) $snapshot->last()->id;
            $stats['last_local_id_processed'] = $cursor;

            if ($remaining !== null) {
                $remaining -= $snapshot->count();
            }
        }

        $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);
        $stats['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        return $stats;
    }

    private function nextRows(?int $afterId, int $limit): EloquentCollection
    {
        return SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
            ->select(self::SELECT_FIELDS)
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  EloquentCollection<int, SalesforceOpportunity>  $rows
     * @param  array<string, mixed>  $stats
     * @return array<string, array<string, mixed>>
     */
    private function querySalesforce(EloquentCollection $rows, array &$stats): array
    {
        $ids = $rows->pluck('salesforce_id')->map(fn (mixed $id): string => (string) $id)->values()->all();
        if ($ids === []) {
            return [];
        }

        $stats['ids_consulted'] += count($ids);
        $requested = array_fill_keys(array_map($this->canonicalSalesforceId(...), $ids), true);
        $records = collect($this->client->queryAll($this->soql($ids)))
            ->filter(function (mixed $record) use ($requested): bool {
                if (! is_array($record)) {
                    return false;
                }

                $id = (string) data_get($record, 'Id');

                return $this->isValidOpportunityId($id)
                    && isset($requested[$this->canonicalSalesforceId($id)]);
            })
            ->keyBy(fn (array $record): string => $this->canonicalSalesforceId((string) data_get($record, 'Id')))
            ->all();

        foreach ($ids as $id) {
            $record = $records[$this->canonicalSalesforceId($id)] ?? null;
            if ($record === null) {
                $stats['ids_not_found_in_salesforce']++;
                $this->appendSamples($stats['samples']['missing_salesforce_ids'], [$id]);
            } else {
                $stats['ids_found_in_salesforce']++;
                $stats[(bool) data_get($record, 'IsDeleted') ? 'deleted_found' : 'active_found']++;
            }
        }

        return $records;
    }

    /** @param list<string> $ids */
    private function soql(array $ids): string
    {
        foreach ($ids as $id) {
            if (! $this->isValidOpportunityId($id)) {
                throw new RuntimeException('Salesforce Opportunity ID local no valido para SOQL.');
            }
        }

        $quotedIds = collect($ids)->map(fn (string $id): string => "'{$id}'")->implode(', ');

        return <<<SOQL
SELECT Id, IsDeleted, SystemModStamp
FROM Opportunity
WHERE Id IN ({$quotedIds})
SOQL;
    }

    /** @param array<string, array<string, mixed>> $records @return array<string, mixed> */
    private function processDryRunChunk(EloquentCollection $snapshot, array $records): array
    {
        $stats = $this->emptyStats();

        foreach ($snapshot as $row) {
            if (! $this->isValidOpportunityId((string) $row->salesforce_id)) {
                continue;
            }

            $candidate = $this->candidateFor($row, $records[$this->canonicalSalesforceId((string) $row->salesforce_id)] ?? null);
            $this->registerPendingCanonicalRefresh(
                $stats,
                $row,
                $records[$this->canonicalSalesforceId((string) $row->salesforce_id)] ?? null,
            );
            if ($this->lifecycleChanges($row, $candidate) !== []) {
                $this->registerChange($stats, $row, $candidate, false);
            } else {
                $stats['rows_unchanged']++;
            }
        }

        return $stats;
    }

    /** @param array<string, array<string, mixed>> $records @return array<string, mixed> */
    private function processApplyChunk(EloquentCollection $snapshot, array $records): array
    {
        $ids = $snapshot->modelKeys();
        $snapshotState = $snapshot->mapWithKeys(
            fn (SalesforceOpportunity $row): array => [(int) $row->id => $this->concurrencyFingerprint($row)],
        );
        $this->beforeApplyTransaction($ids);

        return DB::transaction(function () use ($ids, $records, $snapshotState): array {
            $lockedRows = SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
                ->select(self::SELECT_FIELDS)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $stats = $this->emptyStats();
            $updates = [];

            foreach ($lockedRows as $row) {
                if ($this->concurrencyFingerprint($row) !== $snapshotState->get((int) $row->id)) {
                    $stats['rows_skipped_concurrent']++;

                    continue;
                }

                if (! $this->isValidOpportunityId((string) $row->salesforce_id)) {
                    continue;
                }

                $candidate = $this->candidateFor($row, $records[$this->canonicalSalesforceId((string) $row->salesforce_id)] ?? null);
                $this->registerPendingCanonicalRefresh(
                    $stats,
                    $row,
                    $records[$this->canonicalSalesforceId((string) $row->salesforce_id)] ?? null,
                );
                $changes = $this->lifecycleChanges($row, $candidate);
                if ($changes === []) {
                    $stats['rows_unchanged']++;

                    continue;
                }

                $updates[] = ['id' => (int) $row->id, 'values' => $candidate];
                $this->registerChange($stats, $row, $candidate, true);
            }

            $this->bulkUpdateExisting($updates);

            return $stats;
        });
    }

    /** @param list<int> $localIds */
    protected function beforeApplyTransaction(array $localIds): void {}

    /** @param array<string, mixed>|null $record @return array<string, mixed> */
    private function candidateFor(SalesforceOpportunity $row, ?array $record): array
    {
        if ($record === null) {
            if ($row->isConfirmedDeleted()) {
                return $this->currentLifecycle($row);
            }

            return [
                'is_deleted' => false,
                'salesforce_deleted_at' => null,
                'deletion_detection_source' => SalesforceOpportunity::PRESENCE_SOURCE_MISSING,
            ];
        }

        if ((bool) data_get($record, 'IsDeleted')) {
            return [
                'is_deleted' => true,
                'salesforce_deleted_at' => $this->parseDateTime(data_get($record, 'SystemModStamp')),
                'deletion_detection_source' => SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL,
            ];
        }

        if ($row->isConfirmedDeleted()) {
            return $this->currentLifecycle($row);
        }

        return ['is_deleted' => false, 'salesforce_deleted_at' => null, 'deletion_detection_source' => null];
    }

    /** @return array<string, mixed> */
    private function currentLifecycle(SalesforceOpportunity $row): array
    {
        return [
            'is_deleted' => (bool) $row->is_deleted,
            'salesforce_deleted_at' => $row->salesforce_deleted_at,
            'deletion_detection_source' => $row->deletion_detection_source,
        ];
    }

    private function concurrencyFingerprint(SalesforceOpportunity $row): string
    {
        return implode('|', [
            (string) $row->getRawOriginal('updated_at'),
            (string) ((int) $row->is_deleted),
            (string) $row->getRawOriginal('salesforce_deleted_at'),
            (string) $row->deletion_detection_source,
        ]);
    }

    /** @param array<string, mixed>|null $record */
    private function requiresCanonicalRefresh(SalesforceOpportunity $row, ?array $record): bool
    {
        return $row->isConfirmedDeleted()
            && $record !== null
            && ! (bool) data_get($record, 'IsDeleted');
    }

    /** @param array<string, mixed> $stats @param array<string, mixed>|null $record */
    private function registerPendingCanonicalRefresh(array &$stats, SalesforceOpportunity $row, ?array $record): void
    {
        if (! $this->requiresCanonicalRefresh($row, $record)) {
            return;
        }

        $stats['rows_pending_canonical_refresh']++;
        $this->appendSamples(
            $stats['samples']['pending_canonical_refresh_salesforce_ids'],
            [(string) $row->salesforce_id],
        );
    }

    /** @param array<string, mixed> $candidate @return array<string, mixed> */
    private function lifecycleChanges(SalesforceOpportunity $row, array $candidate): array
    {
        $changes = [];

        foreach (['is_deleted', 'deletion_detection_source'] as $field) {
            if ($row->{$field} !== $candidate[$field]) {
                $changes[$field] = $candidate[$field];
            }
        }

        $currentDeletedAt = $row->salesforce_deleted_at?->toIso8601String();
        $candidateDeletedAt = $candidate['salesforce_deleted_at']?->toIso8601String();
        if ($currentDeletedAt !== $candidateDeletedAt) {
            $changes['salesforce_deleted_at'] = $candidate['salesforce_deleted_at'];
        }

        return $changes;
    }

    /** @param array<string, mixed> $stats @param array<string, mixed> $candidate */
    private function registerChange(array &$stats, SalesforceOpportunity $row, array $candidate, bool $changed): void
    {
        $stats['rows_would_change']++;
        $stats['rows_changed'] += (int) $changed;
        $isMissing = $candidate['deletion_detection_source'] === SalesforceOpportunity::PRESENCE_SOURCE_MISSING;
        $stats['rows_marked_deleted'] += (int) $candidate['is_deleted'];
        $stats['rows_activated'] += (int) (! $candidate['is_deleted'] && ! $isMissing);
        $stats['rows_marked_missing'] += (int) $isMissing;
        $this->appendSamples($stats['samples']['changed_salesforce_ids'], [(string) $row->salesforce_id]);
    }

    /** @param list<array{id:int,values:array<string, mixed>}> $updates */
    private function bulkUpdateExisting(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable('salesforce_opportunities');
        $idColumn = $grammar->wrap('id');
        $bindings = [];
        $assignments = [];

        foreach (['is_deleted', 'salesforce_deleted_at', 'deletion_detection_source'] as $column) {
            $cases = [];
            foreach ($updates as $update) {
                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = $update['id'];
                $bindings[] = $update['values'][$column];
            }
            $wrappedColumn = $grammar->wrap($column);
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

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function isValidOpportunityId(string $id): bool
    {
        return preg_match('/^006[A-Za-z0-9]{12}(?:[A-Za-z0-9]{3})?$/', $id) === 1;
    }

    private function canonicalSalesforceId(string $id): string
    {
        return substr($id, 0, 15);
    }

    private function invalidateAffectedCaches(): void
    {
        foreach (['reservas_ventas_dashboard_cache_version', 'campaign_dashboard_cache_version'] as $key) {
            Cache::forever($key, ((int) Cache::get($key, 1)) + 1);
        }
    }

    /** @return array<string, mixed> */
    private function initialStats(bool $apply, string $runIdentifier): array
    {
        return array_merge($this->emptyStats(), [
            'mode' => $apply ? 'apply' : 'dry-run',
            'run_identifier' => $runIdentifier,
            'last_local_id_processed' => null,
            'duration_seconds' => 0.0,
            'peak_memory_mb' => 0.0,
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyStats(): array
    {
        return [
            'rows_examined' => 0,
            'ids_valid' => 0,
            'ids_invalid' => 0,
            'ids_consulted' => 0,
            'ids_found_in_salesforce' => 0,
            'ids_not_found_in_salesforce' => 0,
            'active_found' => 0,
            'deleted_found' => 0,
            'rows_would_change' => 0,
            'rows_changed' => 0,
            'rows_activated' => 0,
            'rows_marked_deleted' => 0,
            'rows_marked_missing' => 0,
            'rows_unchanged' => 0,
            'rows_skipped_concurrent' => 0,
            'rows_pending_canonical_refresh' => 0,
            'failed' => false,
            'error' => null,
            'samples' => [
                'invalid_salesforce_ids' => [],
                'missing_salesforce_ids' => [],
                'changed_salesforce_ids' => [],
                'pending_canonical_refresh_salesforce_ids' => [],
            ],
        ];
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $source */
    private function mergeStats(array &$target, array $source): void
    {
        foreach (array_keys($this->emptyStats()) as $key) {
            if ($key === 'samples') {
                foreach ($source['samples'] as $sampleKey => $values) {
                    $this->appendSamples($target['samples'][$sampleKey], $values);
                }
            } elseif (is_int($target[$key])) {
                $target[$key] += $source[$key];
            }
        }
    }

    /** @param list<string> $target @param list<string> $values */
    private function appendSamples(array &$target, array $values): void
    {
        $target = array_slice(array_values(array_unique(array_merge($target, $values))), 0, self::SAMPLE_LIMIT);
    }
}
