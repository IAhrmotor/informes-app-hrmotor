<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceSaleSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StockSaleValidityService
{
    public const REASON_CLOSED_LOST = 'closed_lost';

    public const REASON_DUPLICATE_VALID_VEHICLE = 'duplicate_valid_vehicle';

    public const REASON_DUPLICATE_NOT_SELECTED = 'duplicate_not_selected';

    public const REASON_DUPLICATE_AMBIGUOUS = 'duplicate_ambiguous';

    public function reconcile(): array
    {
        $opportunities = SalesforceOpportunity::query()
            ->whereIn(
                'salesforce_id',
                SalesforceSaleSnapshot::query()->select('opportunity_salesforce_id'),
            )
            ->get()
            ->keyBy('salesforce_id');
        $snapshots = SalesforceSaleSnapshot::query()->orderBy('id')->get();
        $baseReasons = [];
        $validByVehicle = [];

        foreach ($snapshots as $snapshot) {
            $opportunity = $opportunities->get($snapshot->opportunity_salesforce_id);
            if (! $opportunity) {
                continue;
            }

            $reason = $this->baseInvalidReason($opportunity);
            $baseReasons[$snapshot->id] = $reason;
            if ($reason === null && filled($snapshot->vehicle_salesforce_id)) {
                $validByVehicle[$snapshot->vehicle_salesforce_id][] = $snapshot->id;
            }
        }

        $duplicateResolutions = [];
        foreach ($validByVehicle as $snapshotIds) {
            if (count($snapshotIds) <= 1) {
                continue;
            }
            $candidates = $snapshots->whereIn('id', $snapshotIds);
            $latestDate = $candidates->max(fn (SalesforceSaleSnapshot $snapshot): string => $snapshot->signed_date?->format('Y-m-d') ?? '');
            $latest = $candidates->filter(fn (SalesforceSaleSnapshot $snapshot): bool => ($snapshot->signed_date?->format('Y-m-d') ?? '') === $latestDate);
            if ($latestDate === '' || $latest->count() !== 1) {
                foreach ($candidates as $candidate) {
                    $duplicateResolutions[$candidate->id] = [
                        'reason' => self::REASON_DUPLICATE_AMBIGUOUS,
                        'selected' => null,
                        'detail' => 'same_latest_signed_date',
                    ];
                }

                continue;
            }
            $selected = $latest->first();
            foreach ($candidates as $candidate) {
                if ($candidate->is($selected)) {
                    continue;
                }
                $duplicateResolutions[$candidate->id] = [
                    'reason' => self::REASON_DUPLICATE_NOT_SELECTED,
                    'selected' => $selected->opportunity_salesforce_id,
                    'detail' => 'latest_signed_date_selected',
                ];
            }
        }
        $result = [
            'valid' => 0,
            'invalid' => 0,
            'duplicates' => count($duplicateResolutions),
            'unchecked' => 0,
        ];

        foreach ($snapshots as $snapshot) {
            $opportunity = $opportunities->get($snapshot->opportunity_salesforce_id);
            if (! $opportunity) {
                $result['unchecked']++;

                continue;
            }

            $reason = $baseReasons[$snapshot->id] ?? null;
            $duplicateResolution = $reason === null ? ($duplicateResolutions[$snapshot->id] ?? null) : null;
            if ($duplicateResolution !== null) {
                $reason = $duplicateResolution['reason'];
            }
            $isValid = $reason === null;
            $updates = [
                'current_stage_name' => $opportunity->stage_name,
                'is_valid' => $isValid,
                'validity_checked_at' => now(),
                'invalid_reason' => $reason,
                'selected_opportunity_salesforce_id' => $duplicateResolution['selected'] ?? null,
                'duplicate_resolution_reason' => $duplicateResolution['detail'] ?? null,
            ];
            if ($isValid) {
                $updates['invalidated_at'] = null;
                $result['valid']++;
            } else {
                $updates['invalidated_at'] = $snapshot->invalidated_at ?? now();
                $result['invalid']++;
            }
            $snapshot->update($updates);
        }

        return $result;
    }

    public function duplicateVehicleIds(): Collection
    {
        return SalesforceSaleSnapshot::query()
            ->whereIn('invalid_reason', [self::REASON_DUPLICATE_NOT_SELECTED, self::REASON_DUPLICATE_AMBIGUOUS])
            ->whereNotNull('vehicle_salesforce_id')
            ->pluck('vehicle_salesforce_id')
            ->unique()
            ->values();
    }

    private function baseInvalidReason(SalesforceOpportunity $opportunity): ?string
    {
        if (! $opportunity->cv_signed) {
            return 'contract_not_signed';
        }
        if (! $opportunity->cv_signed_date) {
            return 'missing_signed_date';
        }
        if (! in_array($opportunity->record_type_name, ['Venta', 'Cambio'], true)) {
            return 'invalid_record_type';
        }
        if ($this->stageKey($opportunity->stage_name) === 'cerrada perdida') {
            return self::REASON_CLOSED_LOST;
        }
        if (blank($opportunity->vehicle_interest_id)) {
            return 'missing_vehicle_interest';
        }

        return null;
    }

    private function stageKey(?string $stage): string
    {
        return Str::of((string) $stage)->lower()->ascii()->squish()->toString();
    }
}
