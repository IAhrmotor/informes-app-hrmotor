<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\SalesforceDelegationManagerHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DelegationManagerEvidenceService
{
    public const TYPE_MONTH_END = 'month_end';

    public const TYPE_FULL_MONTH = 'full_month';

    private const SOURCES = ['salesforce_export', 'it_confirmation', 'direction_confirmation'];

    public function __construct(private readonly CommercialCommissionFormulaConfigService $formulaConfig) {}

    /** @param array<string, mixed> $input */
    public function validate(array $input): array
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', trim((string) ($input['month'] ?? '')));
        $delegationId = trim((string) ($input['delegation_id'] ?? ''));
        $managerId = trim((string) ($input['manager_id'] ?? ''));
        $delegationName = trim((string) ($input['delegation_name'] ?? ''));
        $managerName = trim((string) ($input['manager_name'] ?? ''));
        $source = trim((string) ($input['source'] ?? ''));
        $reference = trim((string) ($input['reference'] ?? ''));
        $recordedBy = trim((string) ($input['recorded_by'] ?? ''));
        $type = trim((string) ($input['evidence_type'] ?? self::TYPE_MONTH_END));

        $errors = [];
        if (! $month) {
            $errors[] = 'month debe usar Y-m';
        }
        if (! $this->validSalesforceId($delegationId)) {
            $errors[] = 'delegation_id no es un ID Salesforce valido';
        }
        if (! $this->validSalesforceUserId($managerId)) {
            $errors[] = 'manager_id no es un ID Salesforce valido';
        }
        if ($delegationName === '' || $managerName === '') {
            $errors[] = 'delegation_name y manager_name son obligatorios';
        }
        if (! in_array($source, self::SOURCES, true)) {
            $errors[] = 'source no esta permitido';
        }
        if (! in_array($type, [self::TYPE_MONTH_END, self::TYPE_FULL_MONTH], true)) {
            $errors[] = 'evidence_type debe ser month_end o full_month';
        }
        if ($reference === '' || mb_strlen($reference) > 255 || $recordedBy === '' || mb_strlen($recordedBy) > 255) {
            $errors[] = 'reference y recorded_by son obligatorios y no pueden superar 255 caracteres';
        }
        if ($this->validSalesforceId($delegationId) && Schema::hasTable('salesforce_delegation_manager_history')
            && ! SalesforceDelegationManagerHistory::query()->where('delegation_salesforce_id', $delegationId)->exists()) {
            $errors[] = 'delegation_id no existe en el catalogo local observado; sincroniza delegaciones antes del backfill';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['evidence' => $errors]);
        }

        return [
            'delegation_id' => $delegationId,
            'delegation_name' => $delegationName,
            'manager_id' => $managerId,
            'manager_name' => $managerName,
            'month' => $month,
            'source' => $source,
            'reference' => $reference,
            'recorded_by' => $recordedBy,
            'evidence_type' => $type,
        ];
    }

    /** @param array<string, mixed> $validated */
    public function record(array $validated): SalesforceDelegationManagerHistory
    {
        /** @var CarbonImmutable $month */
        $month = $validated['month'];
        $fullMonth = $validated['evidence_type'] === self::TYPE_FULL_MONTH;
        $coverageFrom = $fullMonth ? $month->startOfMonth() : $month->addMonth()->startOfMonth()->subSecond();
        $coverageTo = $month->addMonth()->startOfMonth();
        $prefix = $fullMonth ? 'confirmed-full' : 'confirmed-close';

        return SalesforceDelegationManagerHistory::query()->updateOrCreate(
            ['source_key' => $prefix.':'.$validated['delegation_id'].':'.$month->format('Y-m')],
            [
                'delegation_salesforce_id' => $validated['delegation_id'],
                'delegation_name' => $validated['delegation_name'],
                'delegation_key' => $this->formulaConfig->delegationKey($validated['delegation_name']),
                'manager_salesforce_user_id' => $validated['manager_id'],
                'manager_name' => $validated['manager_name'],
                'effective_at' => $coverageFrom,
                'coverage_from' => $coverageFrom,
                'coverage_to' => $coverageTo,
                'observed_at' => now(),
                'source' => $validated['source'],
                'evidence_reference' => $validated['reference'],
                'recorded_by' => $validated['recorded_by'],
                'history_verified' => $fullMonth,
            ],
        );
    }

    private function validSalesforceId(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9]{15}(?:[a-zA-Z0-9]{3})?$/', $value);
    }

    private function validSalesforceUserId(string $value): bool
    {
        return str_starts_with($value, '005') && $this->validSalesforceId($value);
    }
}
