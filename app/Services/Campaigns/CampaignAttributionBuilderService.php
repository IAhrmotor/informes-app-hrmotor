<?php

namespace App\Services\Campaigns;

use App\Models\CampaignAttribution;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Reports\Leads\SalesforceLeadDashboardDatasetService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CampaignAttributionBuilderService
{
    public function __construct(
        private readonly CampaignValueNormalizer $normalizer,
        private readonly SalesforceLeadDashboardDatasetService $leadDataset,
    ) {
    }

    public function build(CarbonInterface $start, CarbonInterface $end, int $windowDays = 30): array
    {
        $start = CarbonImmutable::parse($start)->startOfDay();
        $end = CarbonImmutable::parse($end);
        $windowDays = max($windowDays, 1);
        $metrics = $this->metricLookup($start, $end);
        $leads = $this->campaignLeads($start, $end);
        $opportunities = $this->candidateOpportunities($start, $end->addDays($windowDays), $leads);
        $assignments = $this->assignOpportunities($leads, $opportunities, $windowDays);
        $stats = [
            'processed_leads' => $leads->count(),
            'saved_attributions' => 0,
            'matched_to_platform' => 0,
            'opportunities' => 0,
            'reservations' => 0,
            'fallen_reservations' => 0,
            'sales' => 0,
            'warnings' => [],
        ];

        DB::transaction(function () use ($start, $end, $windowDays, $metrics, $leads, $assignments, &$stats): void {
            CampaignAttribution::query()
                ->where('lead_created_at', '>=', $start)
                ->where('lead_created_at', '<', $end)
                ->delete();

            foreach ($leads as $lead) {
                $campaign = $this->resolveCampaign($lead, $metrics);

                if ($campaign['matched_to_platform']) {
                    $stats['matched_to_platform']++;
                }

                $opportunity = $assignments[(string) $lead->salesforce_id] ?? null;
                $opportunityFlags = $this->opportunityFlags($lead, $opportunity, $windowDays);
                $decorated = $this->leadDataset->decorateLead($lead);

                CampaignAttribution::query()->create([
                    'lead_id' => $lead->salesforce_id,
                    'opportunity_id' => $opportunity?->salesforce_id,
                    'platform' => $campaign['platform'],
                    'account_id' => $campaign['account_id'],
                    'campaign_id' => $campaign['campaign_id'],
                    'campaign_name' => $campaign['campaign_name'],
                    'campaign_name_key' => $this->normalizer->key($campaign['campaign_name']),
                    'source_acquired' => $lead->fuente_origen,
                    'medium_acquired' => $lead->medio_origen,
                    'campaign_acquired' => $lead->campaign_acquired,
                    'acquired_id' => $lead->acquired_id,
                    'acquired_id_key' => $this->normalizer->compactKey($lead->acquired_id),
                    'content_acquired' => $lead->content_acquired,
                    'content_acquired_key' => $this->normalizer->compactKey($lead->content_acquired),
                    'vehicle_interest' => $lead->vehicle_interest,
                    'lead_status' => $lead->status,
                    'lead_created_at' => $lead->created_date,
                    'opportunity_created_at' => $opportunity?->created_date,
                    'reservation_date' => $opportunityFlags['reservation_date'],
                    'sale_date' => $opportunityFlags['sale_date'],
                    'sale_amount' => null,
                    'has_opportunity' => $opportunityFlags['has_opportunity'],
                    'has_reservation' => $opportunityFlags['has_reservation'],
                    'has_fallen_reservation' => $opportunityFlags['has_fallen_reservation'],
                    'has_sale' => $opportunityFlags['has_sale'],
                    'lead_delegation' => $decorated['lead_delegation'] ?? null,
                    'lead_zone' => $decorated['lead_zone'] ?? null,
                    'commercial_user_id' => $decorated['gestor_id'] ?? null,
                    'commercial_user_name' => $decorated['gestor_nombre'] ?? null,
                    'attribution_method' => $campaign['method'],
                    'attribution_confidence' => $campaign['confidence'],
                    'attribution_window_days' => $windowDays,
                ]);

                $stats['saved_attributions']++;
                $stats['opportunities'] += $opportunityFlags['has_opportunity'] ? 1 : 0;
                $stats['reservations'] += $opportunityFlags['has_reservation'] ? 1 : 0;
                $stats['fallen_reservations'] += $opportunityFlags['has_fallen_reservation'] ? 1 : 0;
                $stats['sales'] += $opportunityFlags['has_sale'] ? 1 : 0;
            }
        });

        if ($stats['matched_to_platform'] === 0 && $stats['saved_attributions'] > 0) {
            $stats['warnings'][] = 'Hay campanas Salesforce sin inversion asociada. Revisar IDs/nombres de campana.';
        }

        $this->invalidateCache();

        return $stats;
    }

    private function campaignLeads(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return SalesforceLead::query()
            ->where('created_date', '>=', $start)
            ->where('created_date', '<', $end)
            ->orderBy('created_date')
            ->get()
            ->filter(fn (SalesforceLead $lead): bool => $this->normalizer->hasClearSalesforceAttribution(
                $lead->campaign_acquired,
                $lead->acquired_id,
                $lead->content_acquired,
            ))
            ->values();
    }

    private function metricLookup(CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $start->toDateString())
            ->where('metric_date', '<=', CarbonImmutable::parse($end)->toDateString())
            ->select([
                'platform',
                'account_id',
                'campaign_id',
                'campaign_name',
                'adset_id',
                'ad_group_id',
                'ad_id',
            ])
            ->get();

        $lookup = [
            'ad' => [],
            'adset' => [],
            'ad_group' => [],
            'campaign_id' => [],
            'campaign_name' => [],
        ];

        foreach ($rows as $row) {
            $payload = [
                'platform' => $row->platform,
                'account_id' => $row->account_id,
                'campaign_id' => $row->campaign_id,
                'campaign_name' => $row->campaign_name,
            ];

            foreach ([
                'ad' => $row->ad_id,
                'adset' => $row->adset_id,
                'ad_group' => $row->ad_group_id,
                'campaign_id' => $row->campaign_id,
            ] as $type => $value) {
                $key = $this->normalizer->compactKey($value);

                if ($key !== '') {
                    $lookup[$type][$key] ??= $payload;
                }
            }

            $nameKey = $this->normalizer->key($row->campaign_name);
            if ($nameKey !== '') {
                $lookup['campaign_name'][$nameKey] ??= $payload;
            }
        }

        return $lookup;
    }

    private function resolveCampaign(SalesforceLead $lead, array $metrics): array
    {
        $idCandidates = array_values(array_filter([
            $lead->acquired_id,
            $lead->content_acquired,
        ], fn ($value) => $this->normalizer->isValidAttributionValue($value)));

        foreach (['ad', 'adset', 'ad_group', 'campaign_id'] as $type) {
            foreach ($idCandidates as $candidate) {
                $match = $metrics[$type][$this->normalizer->compactKey($candidate)] ?? null;

                if ($match) {
                    return array_merge($match, [
                        'method' => 'campaign_id_match',
                        'confidence' => 'high',
                        'matched_to_platform' => true,
                    ]);
                }
            }
        }

        if ($this->normalizer->isValidAttributionValue($lead->campaign_acquired)) {
            $nameKey = $this->normalizer->key($lead->campaign_acquired);
            $match = $metrics['campaign_name'][$nameKey] ?? null;

            if ($match) {
                return array_merge($match, [
                    'method' => 'campaign_name_match',
                    'confidence' => 'medium',
                    'matched_to_platform' => true,
                ]);
            }
        }

        $campaignName = $this->normalizer->clean($lead->campaign_acquired)
            ?: $this->normalizer->clean($lead->content_acquired)
            ?: $this->normalizer->clean($lead->acquired_id);

        return [
            'platform' => $this->normalizer->inferPlatform($lead->fuente_origen, $lead->medio_origen, $campaignName),
            'account_id' => null,
            'campaign_id' => $this->normalizer->isValidAttributionValue($lead->acquired_id) ? $lead->acquired_id : null,
            'campaign_name' => $campaignName,
            'method' => $this->normalizer->isValidAttributionValue($lead->acquired_id) ? 'campaign_id_match' : 'campaign_name_match',
            'confidence' => 'medium',
            'matched_to_platform' => false,
        ];
    }

    private function candidateOpportunities(CarbonInterface $start, CarbonInterface $end, Collection $leads): Collection
    {
        $convertedIds = $leads
            ->pluck('converted_opportunity_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return SalesforceOpportunity::query()
            ->where(function ($query) use ($start, $end, $convertedIds): void {
                $query
                    ->whereBetween('created_date', [$start, $end])
                    ->orWhereBetween('reservation_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('cv_signed_date', [$start->toDateString(), $end->toDateString()]);

                if ($convertedIds !== []) {
                    $query->orWhereIn('salesforce_id', $convertedIds);
                }
            })
            ->get()
            ->keyBy('salesforce_id');
    }

    private function assignOpportunities(Collection $leads, Collection $opportunities, int $windowDays): array
    {
        $assignments = [];
        $claimedOpportunityIds = [];

        foreach ($leads as $lead) {
            $opportunityId = (string) $lead->converted_opportunity_id;
            $opportunity = $opportunities->get($opportunityId);

            if (! $opportunity || isset($claimedOpportunityIds[$opportunityId])) {
                continue;
            }

            if (! $this->withinWindow($lead->created_date, $opportunity->created_date, $windowDays)) {
                continue;
            }

            $assignments[(string) $lead->salesforce_id] = $opportunity;
            $claimedOpportunityIds[$opportunityId] = true;
        }

        $leadById = $leads->keyBy('salesforce_id');
        $emailIndex = [];
        $phoneIndex = [];

        foreach ($leads as $lead) {
            foreach ([$lead->email] as $email) {
                $key = $this->normalizeEmail($email);
                if ($key !== null) {
                    $emailIndex[$key][] = $lead->salesforce_id;
                }
            }

            foreach ([$lead->phone, $lead->mobile_phone] as $phone) {
                $key = $this->normalizePhone($phone);
                if ($key !== null) {
                    $phoneIndex[$key][] = $lead->salesforce_id;
                }
            }
        }

        foreach ($opportunities->sortBy('created_date') as $opportunity) {
            if (isset($claimedOpportunityIds[$opportunity->salesforce_id])) {
                continue;
            }

            $candidateLeadIds = collect()
                ->merge($this->indexedValues($emailIndex, $this->normalizeEmail($opportunity->account_person_email)))
                ->merge($this->indexedValues($emailIndex, $this->normalizeEmail($opportunity->account_company_email)))
                ->merge($this->indexedValues($phoneIndex, $this->normalizePhone($opportunity->account_phone)))
                ->filter()
                ->unique();

            $candidate = $candidateLeadIds
                ->map(fn (string $leadId) => $leadById->get($leadId))
                ->filter()
                ->filter(fn (SalesforceLead $lead): bool => $this->withinWindow($lead->created_date, $opportunity->created_date, $windowDays))
                ->sortByDesc('created_date')
                ->first();

            if (! $candidate) {
                continue;
            }

            $assignments[(string) $candidate->salesforce_id] = $opportunity;
            $claimedOpportunityIds[(string) $opportunity->salesforce_id] = true;
        }

        return $assignments;
    }

    private function indexedValues(array $index, ?string $key): array
    {
        if ($key === null) {
            return [];
        }

        return $index[$key] ?? [];
    }

    private function opportunityFlags(SalesforceLead $lead, ?SalesforceOpportunity $opportunity, int $windowDays): array
    {
        if (! $opportunity) {
            return [
                'has_opportunity' => false,
                'has_reservation' => false,
                'has_fallen_reservation' => false,
                'has_sale' => false,
                'reservation_date' => null,
                'sale_date' => null,
            ];
        }

        $stage = (string) $opportunity->stage_name;
        $isClosedLost = strcasecmp($stage, 'Cerrada Perdida') === 0;
        $hasOpportunity = $this->withinWindow($lead->created_date, $opportunity->created_date, $windowDays);
        $hasReservation = (bool) $opportunity->reservation
            && $this->withinWindow($lead->created_date, $opportunity->reservation_date, $windowDays);
        $hasSale = (bool) $opportunity->cv_signed
            && ! $isClosedLost
            && in_array($opportunity->record_type_name, ['Venta', 'Cambio'], true)
            && $this->withinWindow($lead->created_date, $opportunity->cv_signed_date, $windowDays);

        return [
            'has_opportunity' => $hasOpportunity,
            'has_reservation' => $hasReservation,
            'has_fallen_reservation' => $hasReservation && $isClosedLost,
            'has_sale' => $hasSale,
            'reservation_date' => $hasReservation ? $opportunity->reservation_date : null,
            'sale_date' => $hasSale ? $opportunity->cv_signed_date : null,
        ];
    }

    private function withinWindow(mixed $leadDate, mixed $eventDate, int $windowDays): bool
    {
        if (blank($leadDate) || blank($eventDate)) {
            return false;
        }

        $leadAt = CarbonImmutable::parse($leadDate);
        $eventAt = CarbonImmutable::parse($eventDate);

        return $eventAt->greaterThanOrEqualTo($leadAt)
            && $eventAt->lessThanOrEqualTo($leadAt->addDays($windowDays)->endOfDay());
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $value = $this->normalizer->clean($value);

        return $value !== null ? mb_strtolower($value) : null;
    }

    private function normalizePhone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        $digits = preg_replace('/^34(?=\d{9}$)/', '', $digits ?? '');

        return $digits !== '' ? $digits : null;
    }

    private function invalidateCache(): void
    {
        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
    }
}
