<?php

namespace App\Services\Salesforce;

use App\Models\CampaignSalesforceLead;
use App\Models\SalesforceLead;
use App\Services\Campaigns\CampaignValueNormalizer;
use App\Services\Reports\Leads\LeadClassificationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesforceLeadAttributionBackfillService
{
    public const APPLY_LOCK_KEY = 'salesforce_lead_attribution_backfill_apply';

    private const CHUNK_SIZE = 100;

    private const HISTORY_SAMPLE_LIMIT = 20;

    private const APPLY_LOCK_TTL_SECONDS = 21600;

    private const TABLES = [
        'salesforce_leads' => SalesforceLead::class,
        'campaign_salesforce_leads' => CampaignSalesforceLead::class,
    ];

    private const SALESFORCE_FIELDS = [
        'Fuente_origen__c',
        'Medio_origen__c',
        'Canal__c',
        'Delegacion_procedencia__c',
        'Fuente_Adquirida__c',
        'Medio_Adquirido__c',
        'utm_campaign__c',
        'utm_id__c',
        'utm_source__c',
        'utm_medium__c',
        'utm_content__c',
    ];

    private const FIELD_MAP = [
        'source_origin_new' => 'Fuente_origen__c',
        'medium_origin_new' => 'Medio_origen__c',
        'channel_new' => 'Canal__c',
        'delegation_origin_new' => 'Delegacion_procedencia__c',
        'acquired_source_legacy' => 'Fuente_Adquirida__c',
        'acquired_medium_legacy' => 'Medio_Adquirido__c',
        'utm_campaign_new' => 'utm_campaign__c',
        'utm_id_new' => 'utm_id__c',
        'utm_source_new' => 'utm_source__c',
        'utm_medium_new' => 'utm_medium__c',
        'utm_content_new' => 'utm_content__c',
    ];

    private const RESOLUTION_DIMENSIONS = [
        'source',
        'channel',
        'medium',
        'delegation',
        'utm_campaign',
        'utm_id',
        'utm_source',
        'utm_medium',
        'utm_content',
    ];

    private const PLACEHOLDERS = [
        'desconocida',
        'no identificado',
        'sin informar',
        'sin clasificar',
    ];

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly LeadClassificationResolver $classificationResolver,
        private readonly CampaignValueNormalizer $campaignValueNormalizer,
    ) {}

    /**
     * @param  null|callable(string):void  $onSoql
     * @return array<string, mixed>
     */
    public function run(
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $apply,
        ?string $reason = null,
        ?int $limit = null,
        ?string $afterSalesforceId = null,
        ?callable $onSoql = null,
    ): array {
        if ($to->lessThanOrEqualTo($from)) {
            throw new RuntimeException('El rango local del backfill debe cumplir from < to.');
        }

        if ($apply && mb_strlen(trim((string) $reason)) < 10) {
            throw new RuntimeException('El modo apply requiere un motivo operativo de al menos 10 caracteres.');
        }

        if ($limit !== null && $limit < 1) {
            throw new RuntimeException('El limite del backfill debe ser positivo.');
        }

        if ($afterSalesforceId !== null && ! $this->isValidLeadId($afterSalesforceId)) {
            throw new RuntimeException('El cursor del backfill debe ser un Salesforce Lead ID valido.');
        }

        $lock = $apply ? Cache::lock(self::APPLY_LOCK_KEY, self::APPLY_LOCK_TTL_SECONDS) : null;

        if ($lock !== null && ! $lock->get()) {
            throw new RuntimeException('Ya existe otro backfill de atribucion de Leads en modo apply.');
        }

        try {
            return $this->execute(
                $from,
                $to,
                $apply,
                $reason,
                $limit,
                $afterSalesforceId,
                $onSoql,
            );
        } finally {
            $lock?->release();
        }
    }

    /**
     * @param  null|callable(string):void  $onSoql
     * @return array<string, mixed>
     */
    private function execute(
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $apply,
        ?string $reason,
        ?int $limit,
        ?string $afterSalesforceId,
        ?callable $onSoql,
    ): array {
        $startedAt = microtime(true);
        $runIdentifier = (string) Str::uuid();
        $stats = $this->initialStats($from, $to, $apply, $runIdentifier);
        $cursor = $afterSalesforceId;
        $remaining = $limit;

        while ($remaining === null || $remaining > 0) {
            $take = min(self::CHUNK_SIZE, $remaining ?? self::CHUNK_SIZE);
            $ids = $this->nextLocalIds($from, $to, $cursor, $take);

            if ($ids === []) {
                break;
            }

            $validIds = array_values(array_filter($ids, fn (string $id): bool => $this->isValidLeadId($id)));
            $invalidIds = array_values(array_diff($ids, $validIds));
            $queryIdsByCanonicalId = [];
            foreach ($validIds as $validId) {
                $queryIdsByCanonicalId[$this->canonicalSalesforceId($validId)] ??= $validId;
            }
            $queryIds = array_values($queryIdsByCanonicalId);
            $records = [];

            try {
                if ($queryIds !== []) {
                    $soql = $this->soql($queryIds);
                    if ($onSoql !== null) {
                        $onSoql($soql);
                    }
                    $records = collect($this->client->query($soql))
                        ->filter(function (mixed $record) use ($queryIdsByCanonicalId): bool {
                            if (! is_array($record)) {
                                return false;
                            }

                            $returnedId = (string) data_get($record, 'Id');

                            return $this->isValidLeadId($returnedId)
                                && array_key_exists($this->canonicalSalesforceId($returnedId), $queryIdsByCanonicalId);
                        })
                        ->keyBy(fn (array $record): string => $this->canonicalSalesforceId((string) data_get($record, 'Id')))
                        ->all();
                }

                $this->processChunk($ids, $records, $from, $to, $apply, $reason, $runIdentifier, $stats);
            } catch (Throwable $exception) {
                $stats['failed'] = true;
                $stats['error'] = mb_substr($exception->getMessage(), 0, 1000);
                break;
            }

            $stats['salesforce_ids_unique'] += count($queryIds) + count($invalidIds);
            $stats['ids_consulted'] += count($queryIds);
            $stats['ids_invalid_local'] += count($invalidIds);
            $stats['ids_found_in_salesforce'] += count($records);
            $missingIds = collect($queryIdsByCanonicalId)
                ->except(array_keys($records))
                ->values()
                ->all();
            $stats['ids_not_found_in_salesforce'] += count($missingIds);
            $this->appendSample($stats['samples']['missing_salesforce_ids'], $missingIds);
            $this->appendSample($stats['samples']['invalid_local_ids'], $invalidIds);
            $cursor = end($ids);
            $stats['last_salesforce_id_processed'] = $cursor;

            if ($remaining !== null) {
                $remaining -= count($ids);
            }
        }

        if ($apply) {
            $this->invalidateAffectedCaches($stats);
        }

        $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);
        $stats['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        return $stats;
    }

    /** @param list<string> $ids */
    public function soql(array $ids): string
    {
        foreach ($ids as $id) {
            if (! $this->isValidLeadId($id)) {
                throw new RuntimeException('Salesforce Lead ID local no valido para SOQL.');
            }
        }

        $quotedIds = collect($ids)->map(fn (string $id): string => "'{$id}'")->implode(', ');
        $fields = implode(",\n    ", ['Id', ...self::SALESFORCE_FIELDS]);

        return <<<SOQL
SELECT
    {$fields}
FROM Lead
WHERE Id IN ({$quotedIds})
SOQL;
    }

    /** @return list<string> */
    private function nextLocalIds(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $afterSalesforceId,
        int $limit,
    ): array {
        $union = $this->localIdQuery('salesforce_leads', $from, $to)
            ->union($this->localIdQuery('campaign_salesforce_leads', $from, $to));

        return DB::query()
            ->fromSub($union, 'local_lead_ids')
            ->when($afterSalesforceId !== null, fn (Builder $query) => $query->where('salesforce_id', '>', $afterSalesforceId))
            ->orderBy('salesforce_id')
            ->limit($limit)
            ->pluck('salesforce_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function localIdQuery(string $table, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table($table)
            ->select('salesforce_id')
            ->where('created_date', '>=', $from)
            ->where('created_date', '<', $to);
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, array<string, mixed>>  $records
     * @param  array<string, mixed>  $stats
     */
    private function processChunk(
        array $ids,
        array $records,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $apply,
        ?string $reason,
        string $runIdentifier,
        array &$stats,
    ): void {
        if (! $apply) {
            $rowsByTable = $this->loadRows($ids, $from, $to, false);
            $result = $this->analyzeRows($rowsByTable, $records, false, $reason, $runIdentifier);
            $this->mergeChunkStats($stats, $result['stats']);

            return;
        }

        $this->beforeApplyTransaction($ids);

        $result = DB::transaction(function () use ($ids, $records, $from, $to, $reason, $runIdentifier): array {
            $rowsByTable = $this->loadRows($ids, $from, $to, true);
            $result = $this->analyzeRows($rowsByTable, $records, true, $reason, $runIdentifier);

            foreach ($result['updates'] as $table => $updates) {
                $this->bulkUpdateExisting($table, $updates);
            }

            if ($result['history'] !== []) {
                DB::table('salesforce_lead_attribution_backfill_history')->insert($result['history']);
            }

            return $result;
        });

        $this->mergeChunkStats($stats, $result['stats']);
    }

    /**
     * Hook intentionally empty so concurrency can be coordinated deterministically in tests.
     *
     * @param  list<string>  $ids
     */
    protected function beforeApplyTransaction(array $ids): void {}

    /**
     * @param  list<string>  $ids
     * @return array<string, Collection<int, Model>>
     */
    private function loadRows(
        array $ids,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $lockForUpdate,
    ): array {
        $rowsByTable = [];

        foreach (self::TABLES as $table => $modelClass) {
            $query = $modelClass::query()
                ->whereIn('salesforce_id', $ids)
                ->where('created_date', '>=', $from)
                ->where('created_date', '<', $to)
                ->orderBy('salesforce_id');

            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            $rowsByTable[$table] = $query->get();
        }

        return $rowsByTable;
    }

    /**
     * @param  array<string, Collection<int, Model>>  $rowsByTable
     * @param  array<string, array<string, mixed>>  $records
     * @return array{updates:array<string, list<array{salesforce_id:string,values:array<string,mixed>}>>,history:list<array<string,mixed>>,stats:array<string,mixed>}
     */
    private function analyzeRows(
        array $rowsByTable,
        array $records,
        bool $prepareWrites,
        ?string $reason,
        string $runIdentifier,
    ): array {
        $updatesByTable = [];
        $history = [];
        $chunkStats = $this->emptyChunkStats();

        foreach ($rowsByTable as $table => $rows) {
            $chunkStats['rows_examined'][$table] += $rows->count();

            foreach ($rows as $row) {
                $salesforceId = (string) $row->getAttribute('salesforce_id');
                $record = $records[$this->canonicalSalesforceId($salesforceId)] ?? null;

                if ($record === null) {
                    $chunkStats['rows_unchanged'][$table]++;

                    continue;
                }

                $candidate = $this->candidate($row, $table, $record);
                $this->recordResolutionMetrics($candidate['field_resolution'], $chunkStats);

                if ($table === 'salesforce_leads' && $this->isUtmOnly($row, $candidate)) {
                    $chunkStats['utm_only_detected']++;
                }

                $changes = $this->changes($row, $candidate);

                if ($changes === []) {
                    $chunkStats['rows_unchanged'][$table]++;

                    continue;
                }

                $chunkStats['rows_changed'][$table]++;
                $this->appendSample($chunkStats['samples']['changed_salesforce_ids'], [$salesforceId]);
                $this->appendChangeDetail($chunkStats['samples']['change_details'], $table, $salesforceId, $changes);

                foreach (array_keys($changes) as $field) {
                    $chunkStats['changes_by_field'][$field] = ($chunkStats['changes_by_field'][$field] ?? 0) + 1;
                }

                if (! $prepareWrites) {
                    continue;
                }

                $updatesByTable[$table][] = [
                    'salesforce_id' => $salesforceId,
                    'values' => array_map(fn (array $change): mixed => $change['new'], $changes),
                ];
                $history[] = $this->historyRow(
                    $runIdentifier,
                    $table,
                    $salesforceId,
                    (string) $reason,
                    $changes,
                );
            }
        }

        return [
            'updates' => $updatesByTable,
            'history' => $history,
            'stats' => $chunkStats,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function candidate(Model $row, string $table, array $record): array
    {
        $candidate = [];

        foreach (self::FIELD_MAP as $localField => $salesforceField) {
            $candidate[$localField] = data_get($record, $salesforceField);
        }

        $rawPayload = is_array($row->getAttribute('raw_payload')) ? $row->getAttribute('raw_payload') : [];
        foreach (self::SALESFORCE_FIELDS as $field) {
            $rawPayload[$field] = data_get($record, $field);
        }
        $candidate['raw_payload'] = $rawPayload;

        $classificationInput = $row->getAttributes();
        foreach ($candidate as $field => $value) {
            $classificationInput[$field] = $value;
        }

        if ($table === 'campaign_salesforce_leads') {
            $classificationInput['medio_nuevo'] = data_get($rawPayload, 'Medio_Nuevo__c');
            $classificationInput['fuente_nuevo'] = data_get($rawPayload, 'Fuente_Nuevo__c');
            $classificationInput['portal_text'] = data_get($rawPayload, 'Portal_Text__c');
            $classificationInput['delegacion_encargada'] = $row->getAttribute('delegacion_encargada_id');
        }

        $classification = $this->classificationResolver->resolve($classificationInput);
        $candidate['field_resolution'] = $classification['fields'];

        if ($table === 'salesforce_leads') {
            $candidate['resolved_channel'] = $classification['channel'];
            $candidate['resolved_portal'] = $classification['portal'];
            $candidate['portal_resolution_source'] = $classification['portal_resolution_source'];
        }

        return $candidate;
    }

    /** @return array<string, array{previous:mixed,new:mixed,audit_previous:mixed,audit_new:mixed}> */
    private function changes(Model $row, array $candidate): array
    {
        $changes = [];

        foreach ($candidate as $field => $newValue) {
            $previous = $row->getAttribute($field);

            if ($this->equivalent($previous, $newValue)) {
                continue;
            }

            $changes[$field] = [
                'previous' => $previous,
                'new' => $newValue,
                'audit_previous' => $field === 'raw_payload' ? $this->rawPayloadAuditSubset($previous) : $previous,
                'audit_new' => $field === 'raw_payload' ? $this->rawPayloadAuditSubset($newValue) : $newValue,
            ];
        }

        return $changes;
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        if (is_string($left) && (is_array($right) || is_object($right))) {
            $decoded = json_decode($left, true);
            $left = json_last_error() === JSON_ERROR_NONE ? $decoded : $left;
        }

        if (is_string($right) && (is_array($left) || is_object($left))) {
            $decoded = json_decode($right, true);
            $right = json_last_error() === JSON_ERROR_NONE ? $decoded : $right;
        }

        return $this->normalizeComparable($left) === $this->normalizeComparable($right);
    }

    private function normalizeComparable(mixed $value): mixed
    {
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

    /**
     * @param  list<array{salesforce_id:string,values:array<string,mixed>}>  $updates
     */
    private function bulkUpdateExisting(string $table, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedId = $grammar->wrap('salesforce_id');
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
                $bindings[] = $update['salesforce_id'];
                $bindings[] = $this->databaseValue($update['values'][$column]);
            }

            $assignments[] = "{$wrappedColumn} = CASE {$wrappedId} ".implode(' ', $cases)." ELSE {$wrappedColumn} END";
        }

        $ids = array_column($updates, 'salesforce_id');
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        array_push($bindings, ...$ids);

        DB::update(
            "UPDATE {$wrappedTable} SET ".implode(', ', $assignments)." WHERE {$wrappedId} IN ({$placeholders})",
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

    /**
     * @param  array<string, array{previous:mixed,new:mixed,audit_previous:mixed,audit_new:mixed}>  $changes
     * @return array<string, mixed>
     */
    private function historyRow(
        string $runIdentifier,
        string $table,
        string $salesforceId,
        string $reason,
        array $changes,
    ): array {
        return [
            'run_identifier' => $runIdentifier,
            'source_table' => $table,
            'salesforce_id' => $salesforceId,
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
    private function rawPayloadAuditSubset(mixed $payload): array
    {
        $payload = is_array($payload) ? $payload : [];
        $subset = [];

        foreach (self::SALESFORCE_FIELDS as $field) {
            $subset[$field] = data_get($payload, $field);
        }

        return $subset;
    }

    /** @param array<string, mixed> $resolution @param array<string, mixed> $stats */
    private function recordResolutionMetrics(array $resolution, array &$stats): void
    {
        foreach (self::RESOLUTION_DIMENSIONS as $dimension) {
            $field = data_get($resolution, $dimension, []);
            $stats['conflicts_new_vs_legacy'][$dimension] += (int) data_get($field, 'conflict', false);
            $stats['fallbacks_new_empty_to_legacy'][$dimension] += (int) data_get($field, 'used_fallback', false);

            $newRaw = data_get($field, 'new_raw');
            if ($this->isPlaceholder($newRaw)) {
                $stats['placeholders_new_non_empty'][$dimension]++;
            }
        }
    }

    /** @param array<string, mixed> $candidate */
    private function isUtmOnly(Model $row, array $candidate): bool
    {
        $hasNewUtm = collect([
            'utm_campaign_new',
            'utm_id_new',
            'utm_source_new',
            'utm_medium_new',
            'utm_content_new',
        ])->contains(fn (string $field): bool => trim((string) data_get($candidate, $field)) !== '');

        if (! $hasNewUtm) {
            return false;
        }

        return ! $this->campaignValueNormalizer->hasClearSalesforceAttribution(
            $row->getAttribute('campaign_acquired'),
            $row->getAttribute('acquired_id'),
            $row->getAttribute('content_acquired'),
            $row->getAttribute('fuente_origen'),
            $row->getAttribute('medio_origen'),
        );
    }

    private function isPlaceholder(mixed $value): bool
    {
        $key = Str::of((string) $value)->trim()->lower()->ascii()->toString();

        return in_array($key, self::PLACEHOLDERS, true);
    }

    private function isValidLeadId(string $id): bool
    {
        return preg_match('/^00Q[A-Za-z0-9]{12}(?:[A-Za-z0-9]{3})?$/', $id) === 1;
    }

    private function canonicalSalesforceId(string $id): string
    {
        return substr($id, 0, 15);
    }

    /** @param list<string> $target @param list<string> $values */
    private function appendSample(array &$target, array $values): void
    {
        foreach ($values as $value) {
            if (count($target) >= self::HISTORY_SAMPLE_LIMIT) {
                break;
            }

            if (! in_array($value, $target, true)) {
                $target[] = $value;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $target
     * @param  array<string, array{previous:mixed,new:mixed,audit_previous:mixed,audit_new:mixed}>  $changes
     */
    private function appendChangeDetail(
        array &$target,
        string $table,
        string $salesforceId,
        array $changes,
    ): void {
        if (count($target) >= self::HISTORY_SAMPLE_LIMIT) {
            return;
        }

        $target[] = [
            'source_table' => $table,
            'salesforce_id' => $salesforceId,
            'changed_fields' => array_keys($changes),
            'previous_values' => array_map(fn (array $change): mixed => $change['audit_previous'], $changes),
            'new_values' => array_map(fn (array $change): mixed => $change['audit_new'], $changes),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyChunkStats(): array
    {
        $dimensionZeros = array_fill_keys(self::RESOLUTION_DIMENSIONS, 0);

        return [
            'rows_examined' => array_fill_keys(array_keys(self::TABLES), 0),
            'rows_changed' => array_fill_keys(array_keys(self::TABLES), 0),
            'rows_unchanged' => array_fill_keys(array_keys(self::TABLES), 0),
            'changes_by_field' => [],
            'conflicts_new_vs_legacy' => $dimensionZeros,
            'fallbacks_new_empty_to_legacy' => $dimensionZeros,
            'placeholders_new_non_empty' => $dimensionZeros,
            'utm_only_detected' => 0,
            'samples' => [
                'changed_salesforce_ids' => [],
                'change_details' => [],
            ],
        ];
    }

    /** @param array<string, mixed> $stats @param array<string, mixed> $chunkStats */
    private function mergeChunkStats(array &$stats, array $chunkStats): void
    {
        foreach (['rows_examined', 'rows_changed', 'rows_unchanged'] as $metric) {
            foreach ($chunkStats[$metric] as $table => $count) {
                $stats[$metric][$table] += $count;
            }
        }

        foreach ($chunkStats['changes_by_field'] as $field => $count) {
            $stats['changes_by_field'][$field] = ($stats['changes_by_field'][$field] ?? 0) + $count;
        }

        foreach (['conflicts_new_vs_legacy', 'fallbacks_new_empty_to_legacy', 'placeholders_new_non_empty'] as $metric) {
            foreach ($chunkStats[$metric] as $dimension => $count) {
                $stats[$metric][$dimension] += $count;
            }
        }

        $stats['utm_only_detected'] += $chunkStats['utm_only_detected'];
        $this->appendSample($stats['samples']['changed_salesforce_ids'], $chunkStats['samples']['changed_salesforce_ids']);

        foreach ($chunkStats['samples']['change_details'] as $detail) {
            if (count($stats['samples']['change_details']) >= self::HISTORY_SAMPLE_LIMIT) {
                break;
            }

            $stats['samples']['change_details'][] = $detail;
        }
    }

    /** @return array<string, mixed> */
    private function initialStats(
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $apply,
        string $runIdentifier,
    ): array {
        $dimensionZeros = array_fill_keys(self::RESOLUTION_DIMENSIONS, 0);
        $fieldZeros = array_fill_keys([
            ...array_keys(self::FIELD_MAP),
            'field_resolution',
            'resolved_channel',
            'resolved_portal',
            'portal_resolution_source',
            'raw_payload',
        ], 0);

        return [
            'range' => [
                'from_inclusive' => $from->toDateString(),
                'to_exclusive' => $to->toDateString(),
            ],
            'mode' => $apply ? 'apply' : 'dry-run',
            'run_identifier' => $runIdentifier,
            'rows_examined' => array_fill_keys(array_keys(self::TABLES), 0),
            'salesforce_ids_unique' => 0,
            'ids_consulted' => 0,
            'ids_found_in_salesforce' => 0,
            'ids_not_found_in_salesforce' => 0,
            'ids_invalid_local' => 0,
            'rows_changed' => array_fill_keys(array_keys(self::TABLES), 0),
            'rows_unchanged' => array_fill_keys(array_keys(self::TABLES), 0),
            'changes_by_field' => $fieldZeros,
            'conflicts_new_vs_legacy' => $dimensionZeros,
            'fallbacks_new_empty_to_legacy' => $dimensionZeros,
            'placeholders_new_non_empty' => $dimensionZeros,
            'utm_only_detected' => 0,
            'samples' => [
                'changed_salesforce_ids' => [],
                'change_details' => [],
                'missing_salesforce_ids' => [],
                'invalid_local_ids' => [],
            ],
            'last_salesforce_id_processed' => null,
            'failed' => false,
            'error' => null,
            'duration_seconds' => 0.0,
            'peak_memory_mb' => 0.0,
        ];
    }

    /** @param array<string, mixed> $stats */
    private function invalidateAffectedCaches(array $stats): void
    {
        if ($stats['rows_changed']['salesforce_leads'] > 0) {
            Cache::forever('lead_dashboard_cache_version', ((int) Cache::get('lead_dashboard_cache_version', 1)) + 1);
        }

        if ($stats['rows_changed']['campaign_salesforce_leads'] > 0) {
            Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
        }
    }
}
