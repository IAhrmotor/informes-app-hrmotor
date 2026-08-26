<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\CommercialDelegationSnapshot;
use App\Models\SalesforceUser;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CommercialDelegationSnapshotService
{
    private const COMMERCIAL_PROFILES = [
        'Compra/Venta',
        'Comerciales Partner Community',
    ];

    public function __construct(
        private readonly LeadDelegationNormalizer $delegationNormalizer,
    ) {}

    public function captureCurrentUsers(?CarbonInterface $observedAt = null): array
    {
        $observedAt = CarbonImmutable::parse($observedAt ?? now())->utc()->startOfSecond();
        $created = 0;
        $closed = 0;
        $unchanged = 0;

        SalesforceUser::query()
            ->where(function ($query): void {
                $query->whereIn('profile_name', self::COMMERCIAL_PROFILES)
                    ->orWhereIn('salesforce_id', CommercialDelegationSnapshot::query()
                        ->whereNull('observed_until')
                        ->select('salesforce_user_id'));
            })
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($observedAt, &$created, &$closed, &$unchanged): void {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user, $observedAt, &$created, &$closed, &$unchanged): void {
                        SalesforceUser::query()
                            ->whereKey($user->getKey())
                            ->lockForUpdate()
                            ->firstOrFail();

                        $open = CommercialDelegationSnapshot::query()
                            ->where('salesforce_user_id', $user->salesforce_id)
                            ->whereNull('observed_until')
                            ->lockForUpdate()
                            ->latest('observed_from')
                            ->first();

                        $isCommerciallyEligible = $user->is_active
                            && in_array($user->profile_name, self::COMMERCIAL_PROFILES, true);

                        if (! $isCommerciallyEligible) {
                            if ($open !== null) {
                                $open->update(['observed_until' => $observedAt, 'open_marker' => null]);
                                $closed++;
                            } else {
                                $unchanged++;
                            }

                            return;
                        }

                        $normalized = $this->delegationNormalizer->normalize($user->user_delegation);
                        $delegation = $normalized['is_classified'] ? $normalized['delegation'] : null;
                        $zone = $normalized['is_classified'] ? $normalized['zone'] : null;

                        if ($open !== null && $open->delegation === $delegation && $open->zone === $zone) {
                            $unchanged++;

                            return;
                        }

                        if ($open !== null) {
                            if ($open->observed_from->equalTo($observedAt)) {
                                $open->update(['delegation' => $delegation, 'zone' => $zone]);
                                $unchanged++;

                                return;
                            }

                            $open->update(['observed_until' => $observedAt, 'open_marker' => null]);
                            $closed++;
                        }

                        CommercialDelegationSnapshot::query()->create([
                            'salesforce_user_id' => $user->salesforce_id,
                            'delegation' => $delegation,
                            'zone' => $zone,
                            'observed_from' => $observedAt,
                            'open_marker' => 1,
                            'source' => 'salesforce_user_observation',
                        ]);
                        $created++;
                    });
                }
            });

        return compact('created', 'closed', 'unchanged') + ['observed_at' => $observedAt->toIso8601String()];
    }
}
