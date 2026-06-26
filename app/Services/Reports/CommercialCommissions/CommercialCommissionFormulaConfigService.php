<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialCommissionMonthSetting;
use App\Models\SalesforceOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class CommercialCommissionFormulaConfigService
{
    private const TEMPORARILY_UNLOCKABLE_MONTHS = [
        '2026-04',
        '2026-05',
    ];

    private const TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY = 'commercial_commission_temporarily_unlocked_months';

    public function defaults(): array
    {
        return [
            'sales' => [
                'solo_delivery_amount' => 60.0,
                'shared_owner_delivery_amount' => 30.0,
                'shared_secondary_delivery_amount' => 30.0,
            ],
            'purchases' => [
                'commission_percent' => 0.018,
            ],
            'stock' => [
                'days_threshold' => 150,
                'amount' => 10.0,
            ],
            'bonus' => [
                'start_after_delivery' => 15,
                'amount_per_delivery' => 30.0,
            ],
            'delivery_brackets' => [
                ['max_deliveries' => 6, 'percent' => 0.0],
                ['max_deliveries' => 11, 'percent' => 0.8],
                ['max_deliveries' => null, 'percent' => 1.0],
            ],
            'penalties' => [
                'guarantee_total_threshold' => 3500.0,
                'guarantee_percent' => 0.10,
                'reviews_low_threshold' => 30.0,
                'reviews_mid_threshold' => 50.0,
                'reviews_low_percent' => 0.50,
                'reviews_mid_percent' => 0.10,
                'financing_percentage_threshold' => 40.0,
                'financing_percent' => 0.10,
            ],
            'financing_product_brackets' => [
                ['min_amount' => 50001.0, 'percent' => 0.09],
                ['min_amount' => 30001.0, 'percent' => 0.08],
                ['min_amount' => 25001.0, 'percent' => 0.07],
                ['min_amount' => 17001.0, 'percent' => 0.06],
                ['min_amount' => 12001.0, 'percent' => 0.05],
                ['min_amount' => 8001.0, 'percent' => 0.04],
                ['min_amount' => 5001.0, 'percent' => 0.03],
                ['min_amount' => 1.0, 'percent' => 0.02],
            ],
            'guarantee_product_brackets' => [
                ['min_amount' => 20401.0, 'percent' => 0.11],
                ['min_amount' => 14401.0, 'percent' => 0.09],
                ['min_amount' => 9601.0, 'percent' => 0.07],
                ['min_amount' => 5401.0, 'percent' => 0.06],
                ['min_amount' => 3501.0, 'percent' => 0.04],
                ['min_amount' => 1.0, 'percent' => 0.03],
            ],
            'delegations' => [
                'goals' => [],
            ],
            'delegation_bonus' => [
                'objective_brackets' => [
                    ['min_percent' => 100.0001, 'percent' => 0.0060],
                    ['min_percent' => 100.0, 'percent' => 0.0050],
                    ['min_percent' => 95.0, 'percent' => 0.0045],
                    ['min_percent' => 90.0, 'percent' => 0.0040],
                    ['min_percent' => 85.0, 'percent' => 0.0035],
                    ['min_percent' => 0.0, 'percent' => 0.0],
                ],
                'profitability_ratio_threshold' => 14.0,
                'profitability_bonus_percent' => 0.10,
                'financed_amount_ratio_threshold' => 40.0,
                'financed_amount_bonus_percent' => 0.10,
            ],
        ];
    }

    public function resolveSelectedMonth(?string $month): CarbonImmutable
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
        }

        return $this->openMonth();
    }

    public function openMonth(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth();
    }

    public function isEditableMonth(CarbonImmutable|string $month, ?Request $request = null): bool
    {
        $value = $month instanceof CarbonImmutable
            ? $month->format('Y-m')
            : (string) $month;

        if ($value === $this->openMonth()->format('Y-m')) {
            return true;
        }

        if ($request === null) {
            return false;
        }

        return $this->isTemporarilyUnlocked($request, $value);
    }

    public function forMonth(CarbonImmutable|string $month): array
    {
        $monthKey = $month instanceof CarbonImmutable ? $month->format('Y-m') : (string) $month;
        $defaults = $this->defaults();

        if (! Schema::hasTable('commercial_commission_month_settings')) {
            return $defaults;
        }

        $stored = CommercialCommissionMonthSetting::query()
            ->where('month', $monthKey)
            ->first();

        if (! is_array($stored?->settings)) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $stored->settings);
    }

    public function saveForMonth(string $month, array $settings): void
    {
        CommercialCommissionMonthSetting::query()->updateOrCreate(
            ['month' => $month],
            ['settings' => $settings]
        );
    }

    public function availableDelegations(array $settings = []): array
    {
        $delegations = [];

        if (
            Schema::hasTable('salesforce_opportunities')
            && Schema::hasColumn('salesforce_opportunities', 'owner_delegation')
        ) {
            $storedDelegations = SalesforceOpportunity::query()
                ->whereNotNull('owner_delegation')
                ->where('owner_delegation', '<>', '')
                ->pluck('owner_delegation');

            foreach ($storedDelegations as $delegation) {
                $label = trim((string) $delegation);

                if (! $this->shouldIncludeDelegationLabel($label)) {
                    continue;
                }

                $delegations[$this->delegationKey($label)] = $label;
            }
        }

        foreach (($settings['delegations']['goals'] ?? []) as $goalKey => $goal) {
            $label = trim((string) ($goal['label'] ?? $goalKey));

            if (! $this->shouldIncludeDelegationLabel($label)) {
                continue;
            }

            $delegations[$this->delegationKey($label)] = $label;
        }

        return collect($delegations)
            ->sortBy(fn (string $label) => Str::of($label)->ascii()->lower()->toString())
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    public function delegationKey(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->toString();
    }

    public function shouldIncludeDelegationLabel(?string $value): bool
    {
        $label = trim((string) $value);

        if ($label === '') {
            return false;
        }

        $normalized = Str::of($label)
            ->ascii()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        if (in_array($normalized, ['general', 'call fontellas'], true)) {
            return false;
        }

        return ! str_ends_with($normalized, ' general');
    }

    public function canTemporarilyUnlockMonth(CarbonImmutable|string $month): bool
    {
        $value = $month instanceof CarbonImmutable
            ? $month->format('Y-m')
            : (string) $month;

        return in_array($value, self::TEMPORARILY_UNLOCKABLE_MONTHS, true);
    }

    public function unlockMonth(Request $request, CarbonImmutable|string $month): bool
    {
        $value = $month instanceof CarbonImmutable
            ? $month->format('Y-m')
            : (string) $month;

        if (! $this->canTemporarilyUnlockMonth($value)) {
            return false;
        }

        $months = collect($request->session()->get(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY, []))
            ->push($value)
            ->filter(fn (mixed $item) => is_string($item) && preg_match('/^\d{4}-\d{2}$/', $item) === 1)
            ->unique()
            ->values()
            ->all();

        $request->session()->put(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY, $months);

        return true;
    }

    public function closeMonth(Request $request, CarbonImmutable|string $month): void
    {
        $value = $month instanceof CarbonImmutable
            ? $month->format('Y-m')
            : (string) $month;

        $months = collect($request->session()->get(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY, []))
            ->reject(fn (mixed $item) => (string) $item === $value)
            ->values()
            ->all();

        if ($months === []) {
            $request->session()->forget(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY);
            return;
        }

        $request->session()->put(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY, $months);
    }

    public function isTemporarilyUnlocked(Request $request, CarbonImmutable|string $month): bool
    {
        $value = $month instanceof CarbonImmutable
            ? $month->format('Y-m')
            : (string) $month;

        return collect($request->session()->get(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY, []))
            ->contains(fn (mixed $item) => (string) $item === $value);
    }
}
