<?php

namespace App\Services\Salesforce;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityDateRepairRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesforceOpportunityLifecycleDateRepairService
{
    public const APPLY_LOCK_KEY = 'salesforce_opportunity_lifecycle_date_repair_apply';

    private const APPLY_LOCK_TTL_SECONDS = 21600;

    private const CHUNK_SIZE = 100;

    private const SAMPLE_LIMIT = 20;

    private const SELECT_FIELDS = ['id', 'salesforce_id', 'created_date', 'salesforce_last_modified_at'];

    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    /** @return array<string, mixed> */
    public function run(
        bool $apply,
        ?string $reason = null,
        ?int $limit = null,
        ?int $afterId = null,
    ): array {
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
            throw new RuntimeException('Ya existe otra reparacion de fechas de Opportunities en modo apply.');
        }

        $runIdentifier = (string) Str::uuid();
        $auditRun = null;

        try {
            if ($apply) {
                $auditRun = SalesforceOpportunityDateRepairRun::query()->create([
                    'run_identifier' => $runIdentifier,
                    'reason' => trim((string) $reason),
                    'status' => 'running',
                    'started_at' => now(),
                ]);
            }

            $stats = $this->execute($apply, $limit, $afterId, $runIdentifier);

            $auditRun?->update([
                'status' => $stats['failed'] ? 'failed' : 'completed',
                'finished_at' => now(),
                'rows_examined' => $stats['rows_examined'],
                'rows_changed' => $stats['rows_changed'],
            ]);

            return $stats;
        } catch (Throwable $exception) {
            $auditRun?->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);

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

            $chunkStats = $this->emptyChunkStats();
            $chunkStats['rows_examined'] = $snapshot->count();
            $validRows = $snapshot->filter(fn (SalesforceOpportunity $row): bool => $this->isValidOpportunityId((string) $row->salesforce_id));
            $invalidIds = $snapshot
                ->reject(fn (SalesforceOpportunity $row): bool => $this->isValidOpportunityId((string) $row->salesforce_id))
                ->pluck('salesforce_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();
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
                $stats['failed'] = true;
                $this->mergeStats($stats, $chunkStats);

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
        return SalesforceOpportunity::query()
            ->select(self::SELECT_FIELDS)
            ->whereNull('created_date')
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
        $records = collect($this->client->query($this->soql($ids)))
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

                continue;
            }

            $stats['ids_found_in_salesforce']++;
            $stats['created_date_available'] += (int) ($this->parseDateTime(data_get($record, 'CreatedDate')) !== null);
            $stats['last_modified_date_available'] += (int) ($this->parseDateTime(data_get($record, 'LastModifiedDate')) !== null);
        }

        return $records;
    }

    /**
     * @param  list<string>  $ids
     */
    private function soql(array $ids): string
    {
        foreach ($ids as $id) {
            if (! $this->isValidOpportunityId($id)) {
                throw new RuntimeException('Salesforce Opportunity ID local no valido para SOQL.');
            }
        }

        $quotedIds = collect($ids)->map(fn (string $id): string => "'{$id}'")->implode(', ');

        return <<<SOQL
SELECT Id, CreatedDate, LastModifiedDate
FROM Opportunity
WHERE Id IN ({$quotedIds})
SOQL;
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function processDryRunChunk(EloquentCollection $snapshot, array $records): array
    {
        $stats = $this->emptyRowStats();

        foreach ($snapshot as $row) {
            $record = $records[$this->canonicalSalesforceId((string) $row->salesforce_id)] ?? null;
            if ($record === null || ! $this->isValidOpportunityId((string) $row->salesforce_id)) {
                continue;
            }

            if ($this->dateChanges($row, $record) !== []) {
                $stats['rows_would_change']++;
                $this->appendSamples($stats['samples']['changed_salesforce_ids'], [(string) $row->salesforce_id]);
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function processApplyChunk(EloquentCollection $snapshot, array $records): array
    {
        $ids = $snapshot->modelKeys();
        $this->beforeApplyTransaction($ids);

        return DB::transaction(function () use ($ids, $records): array {
            $lockedRows = SalesforceOpportunity::query()
                ->select(self::SELECT_FIELDS)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $stats = $this->emptyRowStats();
            $updates = [];

            foreach ($lockedRows as $row) {
                if ($row->created_date !== null) {
                    $stats['rows_skipped_concurrent']++;

                    continue;
                }

                $record = $records[$this->canonicalSalesforceId((string) $row->salesforce_id)] ?? null;
                if ($record === null || ! $this->isValidOpportunityId((string) $row->salesforce_id)) {
                    continue;
                }

                $changes = $this->dateChanges($row, $record);
                if ($changes === []) {
                    continue;
                }

                $updates[] = ['id' => (int) $row->id, 'values' => $changes];
                $stats['rows_would_change']++;
                $this->appendSamples($stats['samples']['changed_salesforce_ids'], [(string) $row->salesforce_id]);
            }

            $this->bulkUpdateExisting($updates);
            $stats['rows_changed'] = count($updates);

            return $stats;
        });
    }

    /** @param list<int> $localIds */
    protected function beforeApplyTransaction(array $localIds): void {}

    /** @param array<string, mixed> $record @return array<string, CarbonImmutable> */
    private function dateChanges(SalesforceOpportunity $row, array $record): array
    {
        $changes = [];
        $createdDate = $this->parseDateTime(data_get($record, 'CreatedDate'));
        $lastModifiedDate = $this->parseDateTime(data_get($record, 'LastModifiedDate'));

        if ($row->created_date === null && $createdDate !== null) {
            $changes['created_date'] = $createdDate;
        }

        if ($lastModifiedDate !== null && ! $this->sameInstant($row->salesforce_last_modified_at, $lastModifiedDate)) {
            $changes['salesforce_last_modified_at'] = $lastModifiedDate;
        }

        return $changes;
    }

    private function sameInstant(mixed $current, CarbonImmutable $candidate): bool
    {
        return $current !== null && CarbonImmutable::parse($current)->equalTo($candidate);
    }

    /** @param list<array{id:int,values:array<string, CarbonImmutable>}> $updates */
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

        foreach (['created_date', 'salesforce_last_modified_at'] as $column) {
            $cases = [];
            foreach ($updates as $update) {
                if (! array_key_exists($column, $update['values'])) {
                    continue;
                }

                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = $update['id'];
                $bindings[] = $update['values'][$column];
            }

            if ($cases !== []) {
                $wrappedColumn = $grammar->wrap($column);
                $assignments[] = "{$wrappedColumn} = CASE {$idColumn} ".implode(' ', $cases)." ELSE {$wrappedColumn} END";
            }
        }

        $ids = array_column($updates, 'id');
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        array_push($bindings, ...$ids);

        DB::update(
            "UPDATE {$table} SET ".implode(', ', $assignments)." WHERE {$idColumn} IN ({$placeholders}) AND ".$grammar->wrap('created_date').' IS NULL',
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

    /** @return array<string, mixed> */
    private function initialStats(bool $apply, string $runIdentifier): array
    {
        return array_merge($this->emptyChunkStats(), $this->emptyRowStats(), [
            'mode' => $apply ? 'apply' : 'dry-run',
            'run_identifier' => $runIdentifier,
            'failed' => false,
            'last_local_id_processed' => null,
            'duration_seconds' => 0.0,
            'peak_memory_mb' => 0.0,
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyChunkStats(): array
    {
        return [
            'rows_examined' => 0,
            'ids_valid' => 0,
            'ids_invalid' => 0,
            'ids_consulted' => 0,
            'ids_found_in_salesforce' => 0,
            'ids_not_found_in_salesforce' => 0,
            'created_date_available' => 0,
            'last_modified_date_available' => 0,
            'samples' => [
                'invalid_salesforce_ids' => [],
                'missing_salesforce_ids' => [],
                'changed_salesforce_ids' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyRowStats(): array
    {
        return [
            'rows_would_change' => 0,
            'rows_changed' => 0,
            'rows_skipped_concurrent' => 0,
            'samples' => [
                'invalid_salesforce_ids' => [],
                'missing_salesforce_ids' => [],
                'changed_salesforce_ids' => [],
            ],
        ];
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $source */
    private function mergeStats(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            if ($key === 'samples') {
                foreach ($value as $sample => $ids) {
                    $this->appendSamples($target['samples'][$sample], $ids);
                }

                continue;
            }

            if (is_int($value) || is_float($value)) {
                $target[$key] = ($target[$key] ?? 0) + $value;
            }
        }
    }

    /** @param list<string> $target @param list<string> $ids */
    private function appendSamples(array &$target, array $ids): void
    {
        foreach ($ids as $id) {
            if (count($target) >= self::SAMPLE_LIMIT) {
                return;
            }

            $target[] = $id;
        }
    }
}
