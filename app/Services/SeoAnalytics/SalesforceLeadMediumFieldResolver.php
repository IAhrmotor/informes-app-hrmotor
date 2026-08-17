<?php

namespace App\Services\SeoAnalytics;

use Illuminate\Support\Str;

final class SalesforceLeadMediumFieldResolver
{
    private const KNOWN_CANDIDATES = [
        'LEA_SEL_Medio_Origen__c',
        'Medio_Nuevo__c',
    ];

    /** @param array<string, mixed> $describe
     * @return array{status: string, verified_field: ?string, candidates: array<int, array{api_name: string, label: string, type: string, is_picklist: bool, picklist_values: array<int, string>, has_organic: bool}>}
     */
    public function resolve(array $describe): array
    {
        $candidates = collect($describe['fields'] ?? [])
            ->filter(function (mixed $field): bool {
                if (! is_array($field)) {
                    return false;
                }

                $name = (string) ($field['name'] ?? '');
                $label = Str::lower(Str::ascii((string) ($field['label'] ?? '')));

                return in_array($name, self::KNOWN_CANDIDATES, true)
                    || str_contains($label, 'medio');
            })
            ->map(function (array $field): array {
                $values = collect($field['picklistValues'] ?? [])
                    ->filter(fn (mixed $value): bool => is_array($value) && ($value['active'] ?? true) === true)
                    ->map(fn (array $value): string => trim((string) ($value['value'] ?? '')))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'api_name' => (string) ($field['name'] ?? ''),
                    'label' => (string) ($field['label'] ?? ''),
                    'type' => (string) ($field['type'] ?? ''),
                    'is_picklist' => in_array($field['type'] ?? null, ['picklist', 'multipicklist'], true),
                    'picklist_values' => $values,
                    'has_organic' => in_array('Orgánico', $values, true),
                ];
            })
            ->filter(fn (array $field): bool => $field['api_name'] !== '')
            ->values()
            ->all();

        $matching = collect($candidates)->where('has_organic', true)->values();

        return [
            'status' => match ($matching->count()) {
                1 => 'verified',
                0 => 'not_found',
                default => 'ambiguous',
            },
            'verified_field' => $matching->count() === 1 ? $matching->first()['api_name'] : null,
            'candidates' => $candidates,
        ];
    }
}
