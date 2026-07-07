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
    private const EXCLUDED_NORMALIZED_DELEGATIONS = [
        'call fontellas',
        'general',
        'llica de vall',
        'llica',
        'llica de vall barcelona',
    ];

    private const NORMALIZED_DELEGATION_ALIASES = [
        'san boi' => 'Sant Boi',
        'sant boi' => 'Sant Boi',
        'sant boi de llobregat' => 'Sant Boi',
        'alcala de guadaira' => 'Alcalá de Guadaira',
        'castellon' => 'Castellón',
        'dos hermanas' => 'Dos Hermanas',
        'torrejon' => 'Torrejón',
        'torrejon de ardoz' => 'Torrejón de Ardoz',
        'mallorca' => 'Palma',
        'palma' => 'Palma',
        'palma de mallorca' => 'Palma',
        'villareal' => 'Villareal',
        'villarreal' => 'Villareal',
        'villareal almasora' => 'Villareal',
        'villarreal almassora' => 'Villareal',
        'villarreal almasora' => 'Villareal',
        'villareal almassora' => 'Villareal',
        'almassora' => 'Villareal',
        'almasora' => 'Villareal',
        'malga' => 'Malaga',
        'malaga' => 'Malaga',
    ];

    private const GOOGLE_REVIEWS_LOCATION_BY_DELEGATION = [
        'a coruna' => 'HR Motor || A Coruña',
        'alcala de guadaira' => 'HR Motor || Alcalá de Guadaíra',
        'alcobendas' => 'HR Motor || Alcobendas',
        'alcoy' => 'HR Motor || Alcoy',
        'alicante' => 'HR Motor || Alicante',
        'badalona' => 'HR Motor || Badalona',
        'badajoz' => 'HR Motor || Badajoz',
        'bilbao' => 'HR Motor || Bilbao',
        'castellon' => 'HR Motor || Castellón',
        'collado villalba' => 'HR Motor || Collado Villalba',
        'dos hermanas' => 'HR Motor || Dos Hermanas',
        'elche' => 'HR MOTOR || Elche',
        'gijon' => 'HR Motor || Gijón',
        'girona' => 'HR Motor || Girona',
        'lleida' => 'HR Motor || Lleida',
        'llica de vall' => 'HR Motor || Lliçà de Vall',
        'malaga' => 'HR Motor || Málaga',
        'malaga centro' => 'HR Motor || Málaga Centro',
        'manresa' => 'HR Motor || Manresa',
        'murcia' => 'HR Motor || Murcia',
        'palma' => 'HR Motor || Palma de Mallorca',
        'pamplona' => 'HR Motor || Pamplona',
        'rivas vaciamadrid' => 'HR Motor || Rivas - Vaciamadrid',
        'san sebastian' => 'HR Motor || San Sebastián',
        'sant boi' => 'HR Motor || Sant Boi de Llobregat',
        'sedavi' => 'HR Motor || Sedaví',
        'sevilla centro' => 'HR Motor || Sevilla Centro',
        'torrejon de ardoz' => 'HR Motor || Torrejón de Ardoz',
        'fontellas' => 'HR Motor || Tudela-Fontellas',
        'tudela fontellas' => 'HR Motor || Tudela-Fontellas',
        'valencia' => 'HR Motor || València',
        'valencia paterna' => 'HR Motor || Valencia Paterna',
        'valladolid' => 'HR Motor || Valladolid',
        'villareal' => 'HR Motor || Villarreal',
        'zaragoza' => 'HR Motor || Zaragoza',
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
        $selectedMonth = $month instanceof CarbonImmutable
            ? $month->startOfMonth()
            : CarbonImmutable::createFromFormat('Y-m', (string) $month)->startOfMonth();
        $monthKey = $selectedMonth->format('Y-m');
        $defaults = $this->defaults();
        $defaults['delegations']['goals'] = $this->inheritedDelegationGoals($selectedMonth);

        if (! Schema::hasTable('commercial_commission_month_settings')) {
            return $defaults;
        }

        $stored = CommercialCommissionMonthSetting::query()
            ->where('month', $monthKey)
            ->first();

        if (! is_array($stored?->settings)) {
            return $defaults;
        }

        return $this->normalizeSettings(
            array_replace_recursive($defaults, $stored->settings)
        );
    }

    public function saveForMonth(string $month, array $settings): void
    {
        CommercialCommissionMonthSetting::query()->updateOrCreate(
            ['month' => $month],
            ['settings' => $this->normalizeSettings($settings)]
        );
    }

    public function availableDelegations(array $settings = []): array
    {
        $delegations = [];

        if (
            Schema::hasTable('salesforce_opportunities')
            && (
                Schema::hasColumn('salesforce_opportunities', 'delivery_store')
                || Schema::hasColumn('salesforce_opportunities', 'owner_delegation')
            )
        ) {
            $storedDelegations = SalesforceOpportunity::query()
                ->get(['delivery_store', 'owner_delegation']);

            foreach ($storedDelegations as $opportunity) {
                $label = $this->deliveryDelegationLabel(
                    $opportunity->delivery_store,
                    $opportunity->owner_delegation
                );

                if (! $this->shouldIncludeDelegationLabel($label)) {
                    continue;
                }

                $delegations[$this->delegationKey($label)] = $label;
            }
        }

        foreach (($settings['delegations']['goals'] ?? []) as $goalKey => $goal) {
            $label = $this->normalizeDelegationLabel($goal['label'] ?? $goalKey);

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
        return Str::of($this->normalizeDelegationLabel($value))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->toString();
    }

    public function shouldIncludeDelegationLabel(?string $value): bool
    {
        $label = $this->normalizeDelegationLabel($value);

        if ($label === '') {
            return false;
        }

        $normalized = Str::of($label)
            ->ascii()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        if (in_array($normalized, self::EXCLUDED_NORMALIZED_DELEGATIONS, true)) {
            return false;
        }

        return ! str_ends_with($normalized, ' general');
    }

    public function deliveryDelegationLabel(?string $deliveryStore, ?string $ownerDelegation = null): string
    {
        $deliveryStoreLabel = $this->normalizeDelegationLabel($deliveryStore);

        if ($deliveryStoreLabel !== '') {
            return $deliveryStoreLabel;
        }

        return $this->normalizeDelegationLabel($ownerDelegation);
    }

    public function googleReviewsLocationForDelegation(string $delegationLabel): ?string
    {
        $normalized = $this->normalizedDelegationComparable(
            $this->normalizeDelegationLabel($delegationLabel)
        );

        return self::GOOGLE_REVIEWS_LOCATION_BY_DELEGATION[$normalized] ?? null;
    }

    public function normalizeDelegationLabel(mixed $value): string
    {
        $label = trim((string) $value);

        if ($label === '') {
            return '';
        }

        $label = preg_replace('/[_\/\\\\-]+/u', ' ', $label) ?? $label;
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
        $label = preg_replace('/^hr\s*motor\s+/iu', '', $label) ?? $label;
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);

        if ($label === '') {
            return '';
        }

        $comparable = $this->normalizedDelegationComparable($label);

        if (in_array($comparable, self::EXCLUDED_NORMALIZED_DELEGATIONS, true)) {
            return '';
        }

        if (array_key_exists($comparable, self::NORMALIZED_DELEGATION_ALIASES)) {
            return self::NORMALIZED_DELEGATION_ALIASES[$comparable];
        }

        if (Str::upper($label) === $label) {
            return Str::of(Str::lower($label))
                ->headline()
                ->trim()
                ->toString();
        }

        return $label;
    }

    private function normalizedDelegationComparable(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function normalizeSettings(array $settings): array
    {
        $goals = [];

        foreach (($settings['delegations']['goals'] ?? []) as $goalKey => $goal) {
            $label = $this->normalizeDelegationLabel($goal['label'] ?? $goalKey);
            $key = $this->delegationKey($label);

            if ($key === '' || ! $this->shouldIncludeDelegationLabel($label)) {
                continue;
            }

            $candidate = [
                'label' => $label,
                'target_deliveries' => max(0, (int) ($goal['target_deliveries'] ?? 0)),
            ];

            if (! array_key_exists($key, $goals) || $candidate['target_deliveries'] > 0) {
                $goals[$key] = $candidate;
            }
        }

        $settings['delegations']['goals'] = $goals;

        return $settings;
    }

    public function canTemporarilyUnlockMonth(CarbonImmutable|string $month): bool
    {
        $selectedMonth = $month instanceof CarbonImmutable
            ? $month->startOfMonth()
            : CarbonImmutable::createFromFormat('Y-m', (string) $month)->startOfMonth();

        return $selectedMonth->lessThan($this->openMonth()->startOfMonth());
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

    private function inheritedDelegationGoals(CarbonImmutable $selectedMonth): array
    {
        if (! Schema::hasTable('commercial_commission_month_settings')) {
            return [];
        }

        $cursor = $selectedMonth->subMonthNoOverflow()->startOfMonth();
        $oldestMonth = CarbonImmutable::parse('2020-01-01')->startOfMonth();

        while ($cursor->greaterThanOrEqualTo($oldestMonth)) {
            $stored = CommercialCommissionMonthSetting::query()
                ->where('month', $cursor->format('Y-m'))
                ->first();

            if (is_array($stored?->settings) && ! empty($stored->settings['delegations']['goals'])) {
                return $this->normalizeSettings([
                    'delegations' => [
                        'goals' => $stored->settings['delegations']['goals'],
                    ],
                ])['delegations']['goals'] ?? [];
            }

            $cursor = $cursor->subMonthNoOverflow()->startOfMonth();
        }

        return [];
    }
}
