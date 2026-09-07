<?php

namespace App\Services\Salesforce;

use App\Models\SalesforceLead;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SalesforceLeadPhoneNormalizationBackfillService
{
    public const APPLY_LOCK_KEY = 'salesforce_lead_phone_normalization_backfill_apply';

    private const APPLY_LOCK_TTL_SECONDS = 21600;

    private const CHUNK_SIZE = 500;

    private const SELECT_FIELDS = [
        'id',
        'phone',
        'phone_normalized',
        'mobile_phone',
        'mobile_phone_normalized',
    ];

    public function __construct(
        private readonly SalesforcePhoneNormalizer $phoneNormalizer,
    ) {}

    /** @return array<string, mixed> */
    public function run(bool $apply, ?int $limit = null, ?int $afterId = null): array
    {
        if ($limit !== null && $limit < 1) {
            throw new RuntimeException('El limite debe ser un entero positivo.');
        }

        if ($afterId !== null && $afterId < 1) {
            throw new RuntimeException('El cursor after-id debe ser un ID local positivo.');
        }

        $lock = $apply ? Cache::lock(self::APPLY_LOCK_KEY, self::APPLY_LOCK_TTL_SECONDS) : null;
        if ($lock !== null && ! $lock->get()) {
            throw new RuntimeException('Ya existe otro backfill de normalizacion telefonica de Leads en modo apply.');
        }

        try {
            return $this->execute($apply, $limit, $afterId);
        } finally {
            $lock?->release();
        }
    }

    /** @return array<string, mixed> */
    private function execute(bool $apply, ?int $limit, ?int $afterId): array
    {
        $startedAt = microtime(true);
        $stats = $this->initialStats($apply);
        $remaining = $limit;
        $cursor = $afterId;

        try {
            while ($remaining === null || $remaining > 0) {
                $take = min(self::CHUNK_SIZE, $remaining ?? self::CHUNK_SIZE);
                $snapshot = $this->nextRows($cursor, $take);

                if ($snapshot->isEmpty()) {
                    break;
                }

                $chunkStats = $apply
                    ? $this->processApplyChunk($snapshot)
                    : $this->processRows($snapshot, false);
                $this->mergeStats($stats, $chunkStats);

                $cursor = (int) $snapshot->last()->id;
                $stats['last_local_id_processed'] = $cursor;

                if ($remaining !== null) {
                    $remaining -= $snapshot->count();
                }
            }
        } catch (Throwable) {
            $stats['failed'] = true;
            $stats['error'] = 'processing_failed';
        }

        $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);
        $stats['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        return $stats;
    }

    private function nextRows(?int $afterId, int $limit): EloquentCollection
    {
        return SalesforceLead::query()
            ->select(self::SELECT_FIELDS)
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @return array<string, int> */
    private function processApplyChunk(EloquentCollection $snapshot): array
    {
        $ids = $snapshot->modelKeys();

        return DB::transaction(function () use ($ids): array {
            $rows = SalesforceLead::query()
                ->select(self::SELECT_FIELDS)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            return $this->processRows($rows, true);
        });
    }

    /** @return array<string, int> */
    private function processRows(EloquentCollection $rows, bool $persist): array
    {
        $stats = $this->emptyChunkStats();
        $updates = [];

        foreach ($rows as $row) {
            $phone = $this->phoneNormalizer->normalize($row->phone);
            $mobilePhone = $this->phoneNormalizer->normalize($row->mobile_phone);
            $stats['rows_examined']++;
            $stats['phone_keys_non_null'] += (int) ($phone !== null);
            $stats['mobile_phone_keys_non_null'] += (int) ($mobilePhone !== null);
            $stats['both_null'] += (int) ($phone === null && $mobilePhone === null);
            $stats['max_normalized_length'] = max(
                $stats['max_normalized_length'],
                strlen($phone ?? ''),
                strlen($mobilePhone ?? ''),
            );

            if ($row->phone_normalized === $phone && $row->mobile_phone_normalized === $mobilePhone) {
                $stats['rows_unchanged']++;

                continue;
            }

            $stats['rows_changed']++;
            $updates[] = [
                'id' => (int) $row->id,
                'phone_normalized' => $phone,
                'mobile_phone_normalized' => $mobilePhone,
            ];
        }

        if ($persist) {
            $this->bulkUpdate($updates);
        }

        return $stats;
    }

    /** @param list<array{id:int,phone_normalized:?string,mobile_phone_normalized:?string}> $updates */
    private function bulkUpdate(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable('salesforce_leads');
        $idColumn = $grammar->wrap('id');
        $bindings = [];
        $assignments = [];

        foreach (['phone_normalized', 'mobile_phone_normalized'] as $column) {
            $cases = [];
            foreach ($updates as $update) {
                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = $update['id'];
                $bindings[] = $update[$column];
            }
            $wrappedColumn = $grammar->wrap($column);
            $assignments[] = "{$wrappedColumn} = CASE {$idColumn} ".implode(' ', $cases)." ELSE {$wrappedColumn} END";
        }

        $ids = array_column($updates, 'id');
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        array_push($bindings, ...$ids);

        DB::update(
            "UPDATE {$table} SET ".implode(', ', $assignments)." WHERE {$idColumn} IN ({$placeholders})",
            $bindings,
        );
    }

    /** @return array<string, mixed> */
    private function initialStats(bool $apply): array
    {
        return [
            'mode' => $apply ? 'apply' : 'dry-run',
            ...$this->emptyChunkStats(),
            'last_local_id_processed' => null,
            'failed' => false,
            'error' => null,
            'duration_seconds' => 0.0,
            'peak_memory_mb' => 0.0,
        ];
    }

    /** @return array<string, int> */
    private function emptyChunkStats(): array
    {
        return [
            'rows_examined' => 0,
            'rows_changed' => 0,
            'rows_unchanged' => 0,
            'phone_keys_non_null' => 0,
            'mobile_phone_keys_non_null' => 0,
            'both_null' => 0,
            'max_normalized_length' => 0,
        ];
    }

    /** @param array<string, mixed> $target @param array<string, int> $source */
    private function mergeStats(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            $target[$key] = $key === 'max_normalized_length'
                ? max($target[$key], $value)
                : $target[$key] + $value;
        }
    }
}
