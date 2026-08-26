<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\CommercialDelegationSnapshot;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CommercialPerformanceMonthlyRosterService
{
    public const HISTORICAL_UNCERTIFIED = 'Histórico no certificable';

    private const DATASET_TIMEZONE = 'Europe/Madrid';

    private const COMMERCIAL_PROFILES = ['Compra/Venta', 'Comerciales Partner Community'];

    public function context(Collection $months): array
    {
        $start = $months->first()->startOfMonth()->utc();
        $end = $months->last()->addMonth()->startOfMonth()->utc();
        $snapshots = CommercialDelegationSnapshot::query()
            ->where('observed_from', '<', $end)
            ->where(function ($query) use ($start): void {
                $query->whereNull('observed_until')->orWhere('observed_until', '>', $start);
            })
            ->orderBy('observed_from')
            ->get(['salesforce_user_id', 'delegation', 'zone', 'observed_from', 'observed_until'])
            ->groupBy('salesforce_user_id');
        $users = SalesforceUser::query()
            ->where(function ($query) use ($snapshots): void {
                $query->whereIn('profile_name', self::COMMERCIAL_PROFILES)
                    ->when($snapshots->isNotEmpty(), fn ($users) => $users->orWhereIn('salesforce_id', $snapshots->keys()));
            })
            ->get(['salesforce_id', 'name'])
            ->keyBy('salesforce_id');
        $assignments = [];

        foreach ($months as $month) {
            $monthKey = $month->format('Y-m');
            foreach ($users as $userId => $user) {
                $assignment = $this->certifiedAssignment($month, collect($snapshots->get($userId, [])));
                if ($assignment === null) {
                    continue;
                }

                $assignments[$monthKey][$userId] = [
                    'commercial_id' => (string) $userId,
                    'commercial' => (string) ($user->name ?: $userId),
                    'delegation' => $assignment['delegation'],
                    'zone' => $assignment['zone'],
                    'delegation_certified' => true,
                    'ranking_eligible' => true,
                ];
            }
        }

        return compact('users', 'assignments');
    }

    public function rosterForMonth(array $context, string $month): array
    {
        return $context['assignments'][$month] ?? [];
    }

    public function attribution(array $context, mixed $userId, mixed $userName, mixed $eventAt): array
    {
        $userId = trim((string) $userId);
        $userName = trim((string) $userName);
        if ($userId === '') {
            return $this->incidentAttribution();
        }

        $month = CarbonImmutable::parse($eventAt)->setTimezone(self::DATASET_TIMEZONE)->format('Y-m');
        $certified = $context['assignments'][$month][$userId] ?? null;
        if (is_array($certified)) {
            return $certified;
        }

        return [
            'commercial_id' => $userId,
            'commercial' => $userName !== ''
                ? $userName
                : (string) ($context['users']->get($userId)?->name ?: $userId),
            'delegation' => self::HISTORICAL_UNCERTIFIED,
            'zone' => self::HISTORICAL_UNCERTIFIED,
            'delegation_certified' => false,
            'ranking_eligible' => false,
        ];
    }

    public function incidentAttribution(): array
    {
        return [
            'commercial_id' => null,
            'commercial' => 'Incidencia de datos',
            'delegation' => 'Incidencia de datos',
            'zone' => 'Incidencia de datos',
            'delegation_certified' => false,
            'ranking_eligible' => false,
        ];
    }

    private function certifiedAssignment(CarbonImmutable $month, Collection $snapshots): ?array
    {
        $start = $month->startOfMonth()->utc();
        $monthEnd = $month->addMonth()->startOfMonth()->utc();
        $end = $monthEnd->min(CarbonImmutable::now('UTC'));
        if ($end->lessThanOrEqualTo($start)) {
            return null;
        }
        $cursor = $start;
        $dimensions = collect();

        foreach ($snapshots as $snapshot) {
            $intervalStart = CarbonImmutable::parse($snapshot->observed_from)->utc();
            $intervalEnd = $snapshot->observed_until === null
                ? $end
                : CarbonImmutable::parse($snapshot->observed_until)->utc()->min($end);

            if ($intervalEnd->lessThanOrEqualTo($cursor) || $intervalStart->greaterThanOrEqualTo($end)) {
                continue;
            }

            if ($intervalStart->greaterThan($cursor) || blank($snapshot->delegation) || blank($snapshot->zone)) {
                return null;
            }

            $dimensions->push($snapshot->delegation.'|'.$snapshot->zone);
            if ($intervalEnd->greaterThan($cursor)) {
                $cursor = $intervalEnd;
            }

            if ($cursor->greaterThanOrEqualTo($end)) {
                break;
            }
        }

        if ($cursor->lessThan($end) || $dimensions->unique()->count() !== 1) {
            return null;
        }

        [$delegation, $zone] = explode('|', $dimensions->first(), 2);

        return compact('delegation', 'zone');
    }
}
