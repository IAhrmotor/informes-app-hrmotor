<?php

namespace App\Services\Reports\Calls;

class CallMetricsAggregator
{
    public function __construct(
        private readonly CallClassificationRules $rules,
    ) {
    }

    public function emptyBucket(): array
    {
        return [
            'total_calls' => 0,
            'commercial_direct_calls' => 0,
            'commercial_direct_answered' => 0,
            'commercial_direct_lost' => 0,
            'portal_calls' => 0,
            'portal_answered' => 0,
            'portal_lost' => 0,
            'answered' => 0,
            'not_answered' => 0,
            'inbound' => 0,
            'outbound' => 0,
            'unknown_direction' => 0,
            'answered_commercial' => 0,
            'answered_customer_service' => 0,
            'answered_contact_center' => 0,
            'answered_appraiser' => 0,
            'adjusted_duration_answered_sum' => 0,
            'answered_duration_count' => 0,
        ];
    }

    public function add(array &$bucket, mixed $call): void
    {
        $origin = $this->rules->effectiveOrigin(data_get($call, 'call_origin'), data_get($call, 'portales_raw'));
        $direction = (string) data_get($call, 'direction', 'unknown');
        $team = $this->rules->effectiveTeam(
            data_get($call, 'operational_team'),
            data_get($call, 'operational_user_name'),
            data_get($call, 'owner_profile_name'),
        );
        $isAnswered = data_get($call, 'call_status') === 'answered' || (bool) data_get($call, 'is_answered', false);
        $isLost = ! $isAnswered || data_get($call, 'call_status') === 'not_answered' || (bool) data_get($call, 'is_lost', false);

        $bucket['total_calls']++;
        $bucket['commercial_direct_calls'] += $origin === 'commercial_direct' ? 1 : 0;
        $bucket['commercial_direct_answered'] += $origin === 'commercial_direct' && $isAnswered ? 1 : 0;
        $bucket['commercial_direct_lost'] += $origin === 'commercial_direct' && $isLost ? 1 : 0;
        $bucket['portal_calls'] += $origin === 'portal' ? 1 : 0;
        $bucket['portal_answered'] += $origin === 'portal' && $isAnswered ? 1 : 0;
        $bucket['portal_lost'] += $origin === 'portal' && $isLost ? 1 : 0;
        $bucket['answered'] += $isAnswered ? 1 : 0;
        $bucket['not_answered'] += $isLost ? 1 : 0;
        $bucket['inbound'] += $direction === 'inbound' ? 1 : 0;
        $bucket['outbound'] += $direction === 'outbound' ? 1 : 0;
        $bucket['unknown_direction'] += $direction === 'unknown' ? 1 : 0;
        $bucket['answered_commercial'] += $isAnswered && $team === 'commercial' ? 1 : 0;
        $bucket['answered_customer_service'] += $isAnswered && $team === 'customer_service' ? 1 : 0;
        $bucket['answered_contact_center'] += $isAnswered && $team === 'contact_center' ? 1 : 0;
        $bucket['answered_appraiser'] += $isAnswered && $team === 'appraiser' ? 1 : 0;

        if ($isAnswered) {
            $bucket['adjusted_duration_answered_sum'] += max(0, (int) data_get($call, 'adjusted_duration_seconds', 0));
            $bucket['answered_duration_count']++;
        }
    }

    public function finalize(array $bucket): array
    {
        $total = $bucket['total_calls'];
        $durationCount = $bucket['answered_duration_count'];

        return array_merge($bucket, [
            'answered_calls' => $bucket['answered'],
            'lost_calls' => $bucket['not_answered'],
            'answered_pct' => $this->percentage($bucket['answered'], $total),
            'not_answered_pct' => $this->percentage($bucket['not_answered'], $total),
            'commercial_direct_answered_pct' => $this->percentage($bucket['commercial_direct_answered'], $bucket['commercial_direct_calls']),
            'commercial_direct_lost_pct' => $this->percentage($bucket['commercial_direct_lost'], $bucket['commercial_direct_calls']),
            'portal_answered_pct' => $this->percentage($bucket['portal_answered'], $bucket['portal_calls']),
            'portal_lost_pct' => $this->percentage($bucket['portal_lost'], $bucket['portal_calls']),
            'inbound_pct' => $this->percentage($bucket['inbound'], $total),
            'outbound_pct' => $this->percentage($bucket['outbound'], $total),
            'average_talk_seconds' => $durationCount > 0
                ? round($bucket['adjusted_duration_answered_sum'] / $durationCount, 2)
                : 0.0,
        ]);
    }

    private function percentage(int|float $value, int|float $total): float
    {
        return $total > 0 ? round(($value / $total) * 100, 2) : 0.0;
    }
}
