<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class ChangedRowUpsert
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $excludedFromComparison
     * @param  list<string>  $excludedFromUpdate
     * @return array{inserted:int, updated:int, unchanged:int}
     */
    public function persist(
        string $modelClass,
        array $rows,
        string $uniqueKey,
        array $excludedFromComparison = [],
        array $excludedFromUpdate = [],
    ): array {
        if ($rows === []) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        }

        $ids = array_values(array_unique(array_column($rows, $uniqueKey)));
        $existing = $modelClass::query()
            ->whereIn($uniqueKey, $ids)
            ->get()
            ->keyBy(fn (Model $model): string => (string) $model->getAttribute($uniqueKey));

        $insertedRows = [];
        $updatedRows = [];
        $unchanged = 0;

        foreach ($rows as $row) {
            /** @var Model|null $current */
            $current = $existing->get((string) $row[$uniqueKey]);

            if ($current === null) {
                $insertedRows[] = $row;

                continue;
            }

            if ($this->hasPersistedChange($current, $row, $uniqueKey, $excludedFromComparison)) {
                $updatedRows[] = $row;
            } else {
                $unchanged++;
            }
        }

        $updateColumns = array_values(array_diff(
            array_keys($rows[0]),
            [$uniqueKey, ...$excludedFromUpdate],
        ));

        if ($insertedRows !== []) {
            $modelClass::query()->upsert($insertedRows, [$uniqueKey], $updateColumns);
        }

        if ($updatedRows !== []) {
            $modelClass::query()->upsert($updatedRows, [$uniqueKey], $updateColumns);
        }

        return [
            'inserted' => count($insertedRows),
            'updated' => count($updatedRows),
            'unchanged' => $unchanged,
        ];
    }

    /** @param array<string, mixed> $incoming */
    private function hasPersistedChange(
        Model $current,
        array $incoming,
        string $uniqueKey,
        array $excludedFromComparison,
    ): bool {
        foreach ($incoming as $attribute => $value) {
            if ($attribute === $uniqueKey || in_array($attribute, $excludedFromComparison, true)) {
                continue;
            }

            if ($this->normalize($current, $attribute, $current->getAttribute($attribute))
                !== $this->normalize($current, $attribute, $value)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(Model $model, string $attribute, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cast = strtolower((string) ($model->getCasts()[$attribute] ?? ''));
        $cast = explode(':', $cast, 2)[0];

        if (in_array($cast, ['array', 'json'], true)) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            }

            return $this->normalizeJson($value);
        }

        if (in_array($cast, ['bool', 'boolean'], true)) {
            return (bool) $value;
        }

        if (in_array($cast, ['int', 'integer'], true)) {
            return (int) $value;
        }

        if ($cast === 'date') {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        }

        if (str_starts_with($cast, 'datetime') || $value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private function normalizeJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value === '' ? null : $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeJson($item);
        }

        return $value;
    }
}
