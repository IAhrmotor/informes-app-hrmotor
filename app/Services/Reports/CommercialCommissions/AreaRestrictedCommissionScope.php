<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\SalesforceUser;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AreaRestrictedCommissionScope
{
    public function __construct(
        private readonly LeadDelegationNormalizer $delegationNormalizer,
    ) {
    }

    public function commercialDashboard(array $payload, string $zoneLabel, ?string $reportUserEmail): array
    {
        $summaryRows = collect($payload['summary_rows'] ?? []);
        $visibleCommercialIds = $this->visibleCommercialIds($summaryRows, $zoneLabel, $reportUserEmail);
        $summaryRows = $summaryRows
            ->filter(fn (array $row): bool => $visibleCommercialIds->contains((string) ($row['commercial_id'] ?? '')))
            ->values();
        $delegationRows = collect($payload['delegation_rows'] ?? [])
            ->filter(fn (array $row): bool => $this->belongsToZone($row['delegation_name'] ?? null, $zoneLabel))
            ->values();

        $payload['summary_rows'] = $summaryRows->all();
        $payload['delegation_rows'] = $delegationRows->all();
        $payload['diagnostics'] = $this->scopedDiagnostics(
            $payload['diagnostics'] ?? [],
            $summaryRows,
            $delegationRows,
        );

        return $payload;
    }

    public function delegationAuditRows(array $rows, string $zoneLabel): array
    {
        return collect($rows)
            ->filter(fn (array $row): bool => $this->belongsToZone($row['delegation_calculated'] ?? null, $zoneLabel))
            ->values()
            ->all();
    }

    public function delegationAuditRowsByDelegation(array $rows, string $delegationName): array
    {
        return collect($rows)
            ->filter(fn (array $row): bool => $this->sameDelegation($row['delegation_calculated'] ?? null, $delegationName))
            ->values()
            ->all();
    }

    public function delegationDashboard(array $payload, string $delegationName): array
    {
        $candidateRows = collect($payload['summary_rows'] ?? []);
        $visibleIds = SalesforceUser::query()
            ->whereIn('salesforce_id', $candidateRows->pluck('commercial_id')->filter()->all())
            ->get(['salesforce_id', 'user_delegation'])
            ->filter(fn (SalesforceUser $user): bool => $this->sameDelegation($user->user_delegation, $delegationName))
            ->pluck('salesforce_id');
        $summaryRows = $candidateRows->filter(
            fn (array $row): bool => $visibleIds->contains((string) ($row['commercial_id'] ?? ''))
        )->values();
        $delegationRows = collect($payload['delegation_rows'] ?? [])->filter(
            fn (array $row): bool => $this->sameDelegation($row['delegation_name'] ?? null, $delegationName)
        )->values();
        $payload['summary_rows'] = $summaryRows->all();
        $payload['delegation_rows'] = $delegationRows->all();
        $payload['diagnostics'] = $this->scopedDiagnostics($payload['diagnostics'] ?? [], $summaryRows, $delegationRows);

        return $payload;
    }

    public function commercialDashboardByUser(array $payload, string $salesforceUserId): array
    {
        $summaryRows = collect($payload['summary_rows'] ?? [])->where('commercial_id', $salesforceUserId)->values();
        $payload['summary_rows'] = $summaryRows->all();
        $payload['delegation_rows'] = [];
        $payload['diagnostics'] = $this->scopedDiagnostics($payload['diagnostics'] ?? [], $summaryRows, collect());

        return $payload;
    }

    private function sameDelegation(mixed $actual, string $expected): bool
    {
        return $this->delegationNormalizer->normalize(is_string($actual) ? $actual : null)['delegation']
            === $this->delegationNormalizer->normalize($expected)['delegation'];
    }

    public function belongsToZone(mixed $delegation, string $zoneLabel): bool
    {
        $normalized = $this->delegationNormalizer->normalize(is_string($delegation) ? $delegation : null);

        return ($normalized['zone'] ?? null) === $zoneLabel;
    }

    private function visibleCommercialIds(Collection $summaryRows, string $zoneLabel, ?string $reportUserEmail): Collection
    {
        $commercialIds = $summaryRows
            ->pluck('commercial_id')
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        if ($commercialIds->isEmpty()) {
            return collect();
        }

        $normalizedEmail = Str::lower(trim((string) $reportUserEmail));

        return SalesforceUser::query()
            ->whereIn('salesforce_id', $commercialIds->all())
            ->get(['salesforce_id', 'email', 'user_delegation'])
            ->filter(function (SalesforceUser $user) use ($zoneLabel, $normalizedEmail): bool {
                $isCurrentUser = $normalizedEmail !== ''
                    && Str::lower(trim((string) $user->email)) === $normalizedEmail;

                return $isCurrentUser || $this->belongsToZone($user->user_delegation, $zoneLabel);
            })
            ->pluck('salesforce_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values();
    }

    private function scopedDiagnostics(array $diagnostics, Collection $summaryRows, Collection $delegationRows): array
    {
        if ($summaryRows->isNotEmpty()) {
            return [
                ...$diagnostics,
                'opportunities_total' => (int) $summaryRows->sum('operations_count'),
                'reviews_count' => (int) $summaryRows->sum('reviews_count'),
                'sales_count' => (int) $summaryRows->sum('sales_count'),
                'purchases_count' => (int) ($summaryRows->sum('appraisals_count') + $summaryRows->sum('changes_count')),
                'operations_count' => (int) $summaryRows->sum('operations_count'),
                'shared_sales_count' => (int) $summaryRows->sum('shared_count'),
                'stock_150_count' => (int) $summaryRows->sum('stock_150_count'),
                'commercials_count' => $summaryRows->count(),
            ];
        }

        if ($delegationRows->isNotEmpty()) {
            return [
                ...$diagnostics,
                'opportunities_total' => (int) $delegationRows->sum('deliveries_count'),
                'reviews_count' => (int) $delegationRows->sum('reviews_count'),
                'commercials_count' => 0,
            ];
        }

        return [
            ...$diagnostics,
            'opportunities_total' => 0,
            'reviews_count' => 0,
            'sales_count' => 0,
            'purchases_count' => 0,
            'operations_count' => 0,
            'shared_sales_count' => 0,
            'stock_150_count' => 0,
            'commercials_count' => 0,
        ];
    }
}
