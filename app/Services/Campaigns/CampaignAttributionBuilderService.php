<?php

namespace App\Services\Campaigns;

use App\Services\Reports\Leads\SalesforceLeadDashboardDatasetService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CampaignAttributionBuilderService
{
    private const LEAD_CHUNK_SIZE = 1000;
    private const UPSERT_CHUNK_SIZE = 25;

    public function __construct(
        private readonly CampaignValueNormalizer $normalizer,
        private readonly SalesforceLeadDashboardDatasetService $leadDataset,
        private readonly CampaignSaleAmountResolver $saleAmountResolver,
    ) {
    }

    public function build(CarbonInterface $start, CarbonInterface $end, int $windowDays = 30): array
    {
        $startedAt = microtime(true);
        $start = CarbonImmutable::parse($start)->startOfDay();
        $end = CarbonImmutable::parse($end);
        $windowDays = max($windowDays, 1);
        $metrics = $this->metricLookup($start, $end);
        $stats = $this->emptyStats($start, $end);
        $leads = $this->candidateLeads($start, $end, $stats);
        $opportunities = $this->candidateOpportunities($start, $end->addDays($windowDays), $leads);
        $assignments = $this->assignOpportunities($leads, $opportunities, $windowDays);
        $now = now();

        DB::table('campaign_attributions')
            ->where('lead_created_at', '>=', $start)
            ->where('lead_created_at', '<', $end)
            ->where('attribution_window_days', $windowDays)
            ->delete();

        $batch = [];

        foreach ($leads as $lead) {
            $campaign = $this->resolveCampaign($lead, $metrics);
            $assignment = $assignments[(string) $lead->salesforce_id] ?? null;
            $opportunity = $assignment['opportunity'] ?? null;
            $opportunityFlags = $this->opportunityFlags($lead, $opportunity, $windowDays);
            $decorated = $this->leadDataset->decorateLead($lead);

            $this->countCampaignMatch($stats, $campaign);

            $batch[] = [
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
                'sale_amount' => $opportunityFlags['sale_amount'],
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
                'opportunity_attribution_method' => $assignment['method'] ?? null,
                'opportunity_attribution_confidence' => $assignment['confidence'] ?? null,
                'match_status' => $campaign['match_status'],
                'campaign_source_type' => $campaign['campaign_source_type'],
                'attribution_window_days' => $windowDays,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $stats['saved_attributions']++;
            $stats['opportunities'] += $opportunityFlags['has_opportunity'] ? 1 : 0;
            $stats['reservations'] += $opportunityFlags['has_reservation'] ? 1 : 0;
            $stats['fallen_reservations'] += $opportunityFlags['has_fallen_reservation'] ? 1 : 0;
            $stats['sales'] += $opportunityFlags['has_sale'] ? 1 : 0;
            $this->countSaleAmountStats($stats, $opportunity, $opportunityFlags);

            if (count($batch) >= self::UPSERT_CHUNK_SIZE) {
                $this->flushAttributions($batch);
                $batch = [];
            }
        }

        $this->flushAttributions($batch);

        if ($stats['salesforce_only'] > 0) {
            $stats['warnings'][] = 'Hay campanas Salesforce sin inversion asociada o procedencias sin coste. Revisar IDs/nombres de campana.';
        }

        if ($stats['sales'] > 0 && ! $this->saleAmountResolver->preferredColumnExists()) {
            $stats['warnings'][] = $this->saleAmountResolver->diagnosticMessage();
        }

        $stats['sale_amount_field_used'] = $stats['sales_with_opo_for_importe_total'] > 0
            ? 'opo_for_importe_total'
            : ($stats['sales_with_amount'] > 0 ? 'amount' : 'none');
        $stats['sale_amount_sum'] = round((float) $stats['sale_amount_sum'], 2);
        $stats['duration_seconds'] = round(microtime(true) - $startedAt, 2);
        $stats['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $stats = array_merge($stats, $this->topDiagnostics($start, $end, $windowDays));

        $this->invalidateCache();

        return $stats;
    }

    private function candidateLeads(CarbonInterface $start, CarbonInterface $end, array &$stats): Collection
    {
        $sourceTable = $this->leadSourceTable($start, $end);
        $stats['lead_source_table'] = $sourceTable;

        $base = DB::table($sourceTable)
            ->where('created_date', '>=', $start)
            ->where('created_date', '<', $end);

        $stats['total_leads_in_range'] = (clone $base)->count();

        $leads = collect();

        (clone $base)
            ->orderBy('id')
            ->select($this->leadSelectColumns($sourceTable))
            ->chunkById(self::LEAD_CHUNK_SIZE, function (Collection $chunk) use (&$leads, &$stats): void {
                foreach ($chunk as $lead) {
                    $this->fillCampaignFieldsFromRawPayload($lead);

                    if (! $this->hasAnyAcquisitionValue($lead)) {
                        continue;
                    }

                    $stats['leads_with_acquisition_not_null']++;

                    if (! $this->hasValidAcquisition($lead)) {
                        $stats['discarded_invalid_values']++;

                        continue;
                    }

                    $this->countLeadAcquisitionShape($stats, $lead);
                    $leads->push($lead);
                }
            }, 'id');

        $stats['candidate_leads'] = $leads->count();
        $stats['processed_leads'] = $leads->count();

        return $leads;
    }

    private function leadSourceTable(CarbonInterface $start, CarbonInterface $end): string
    {
        if (! Schema::hasTable('campaign_salesforce_leads')) {
            return 'salesforce_leads';
        }

        $hasCampaignLeads = DB::table('campaign_salesforce_leads')
            ->where('created_date', '>=', $start)
            ->where('created_date', '<', $end)
            ->exists();

        return $hasCampaignLeads ? 'campaign_salesforce_leads' : 'salesforce_leads';
    }

    private function leadSelectColumns(string $sourceTable): array
    {
        if ($sourceTable === 'salesforce_leads') {
            return [
                'id',
                'salesforce_id',
                'name',
                'created_date',
                'status',
                'record_type_name',
                'owner_id',
                'owner_name',
                'persona_que_trabajo_id',
                'persona_que_trabajo_name',
                'propietario_descarte_id',
                'propietario_descarte_name',
                'fuente_origen',
                'medio_origen',
                'campaign_acquired',
                'acquired_id',
                'content_acquired',
                'vehicle_interest',
                'phone',
                'mobile_phone',
                'email',
                'converted_opportunity_id',
                'medio_nuevo',
                'fuente_nuevo',
                'portal_text',
                'delegacion_encargada_text',
                'delegacion_encargada',
                'delegacion_encargada_bueno',
                'raw_payload',
            ];
        }

        return [
            'id',
            'salesforce_id',
            'name',
            'created_date',
            'status',
            DB::raw('NULL as record_type_name'),
            'owner_id',
            'owner_name',
            DB::raw('NULL as persona_que_trabajo_id'),
            DB::raw('NULL as persona_que_trabajo_name'),
            DB::raw('NULL as propietario_descarte_id'),
            DB::raw('NULL as propietario_descarte_name'),
            'fuente_origen',
            'medio_origen',
            'campaign_acquired',
            'acquired_id',
            'content_acquired',
            'vehicle_interest',
            'phone',
            'mobile_phone',
            'email',
            'converted_opportunity_id',
            DB::raw('NULL as medio_nuevo'),
            DB::raw('NULL as fuente_nuevo'),
            DB::raw('NULL as portal_text'),
            'delegacion_encargada_text',
            DB::raw('delegacion_encargada_id as delegacion_encargada'),
            'delegacion_encargada_bueno',
            'raw_payload',
        ];
    }

    private function hasValidAcquisition(object $lead): bool
    {
        return $this->normalizer->hasClearSalesforceAttribution(
            $lead->campaign_acquired,
            $lead->acquired_id,
            $lead->content_acquired,
            $lead->fuente_origen,
            $lead->medio_origen,
        );
    }

    private function hasAnyAcquisitionValue(object $lead): bool
    {
        foreach ([
            $lead->campaign_acquired,
            $lead->acquired_id,
            $lead->content_acquired,
            $lead->fuente_origen,
            $lead->medio_origen,
        ] as $value) {
            if (filled($this->normalizer->clean($value))) {
                return true;
            }
        }

        return false;
    }

    private function fillCampaignFieldsFromRawPayload(object $lead): void
    {
        $payload = $lead->raw_payload ?? null;

        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        if (! is_array($payload)) {
            return;
        }

        foreach ([
            'fuente_origen' => 'LEA_SEL_Fuente_Origen__c',
            'medio_origen' => 'LEA_SEL_Medio_Origen__c',
            'campaign_acquired' => 'Campa_a_Adquirida__c',
            'acquired_id' => 'Id_Adquirido__c',
            'content_acquired' => 'Contenido_Adquirido__c',
            'vehicle_interest' => 'LEA_BUS_Vehiculo_de_interes__c',
            'phone' => 'Phone',
            'mobile_phone' => 'MobilePhone',
            'email' => 'Email',
            'converted_opportunity_id' => 'ConvertedOpportunityId',
        ] as $localField => $salesforceField) {
            if (! $this->normalizer->isValidAttributionValue($lead->{$localField} ?? null) && filled(data_get($payload, $salesforceField))) {
                $lead->{$localField} = data_get($payload, $salesforceField);
            }
        }
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
            'campaign_name_flexible' => [],
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

            $flexibleNameKey = $this->normalizer->flexibleCampaignKey($row->campaign_name);
            if ($flexibleNameKey !== '') {
                $lookup['campaign_name_flexible'][$flexibleNameKey] ??= $payload;
            }
        }

        return $lookup;
    }

    private function resolveCampaign(object $lead, array $metrics): array
    {
        $idCandidates = array_values(array_filter([
            $lead->acquired_id,
            $lead->content_acquired,
        ], fn ($value) => $this->normalizer->isValidAttributionValue($value)));

        foreach ([
            'ad' => 'ad_id_match',
            'adset' => 'adset_or_adgroup_id_match',
            'ad_group' => 'adset_or_adgroup_id_match',
            'campaign_id' => 'campaign_id_match',
        ] as $type => $method) {
            foreach ($idCandidates as $candidate) {
                $match = $metrics[$type][$this->normalizer->compactKey($candidate)] ?? null;

                if ($match) {
                    return array_merge($match, [
                        'method' => $method,
                        'confidence' => 'high',
                        'match_status' => 'Cruzada por ID',
                        'campaign_source_type' => 'platform_campaign',
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
                    'match_status' => 'Cruzada por nombre',
                    'campaign_source_type' => 'platform_campaign',
                    'matched_to_platform' => true,
                ]);
            }

            $flexibleNameKey = $this->normalizer->flexibleCampaignKey($lead->campaign_acquired);
            $match = $metrics['campaign_name_flexible'][$flexibleNameKey] ?? null;

            if ($match) {
                return array_merge($match, [
                    'method' => 'campaign_name_match',
                    'confidence' => 'low',
                    'match_status' => 'Cruzada por nombre',
                    'campaign_source_type' => 'platform_campaign',
                    'matched_to_platform' => true,
                ]);
            }
        }

        $hasCampaign = $this->normalizer->isValidAttributionValue($lead->campaign_acquired);
        $hasOrigin = $this->normalizer->isValidAttributionValue($lead->fuente_origen)
            || $this->normalizer->isValidAttributionValue($lead->medio_origen);
        $sourceType = $hasCampaign ? 'salesforce_campaign_without_spend' : 'salesforce_origin';
        $campaignName = $hasCampaign
            ? $this->normalizer->clean($lead->campaign_acquired)
            : $this->originLabel($lead->fuente_origen, $lead->medio_origen);

        if (! $hasCampaign && ! $hasOrigin) {
            $campaignName = $this->firstValidValue($lead->content_acquired, $lead->acquired_id);
            $sourceType = 'salesforce_campaign_without_spend';
        }

        return [
            'platform' => 'salesforce',
            'account_id' => null,
            'campaign_id' => $sourceType === 'salesforce_campaign_without_spend' && $this->normalizer->isValidAttributionValue($lead->acquired_id) ? $lead->acquired_id : null,
            'campaign_name' => $campaignName,
            'method' => 'salesforce_only',
            'confidence' => 'low',
            'match_status' => $sourceType === 'salesforce_origin' ? 'Procedencia Salesforce' : 'Sin inversion asociada',
            'campaign_source_type' => $sourceType,
            'matched_to_platform' => false,
        ];
    }

    private function originLabel(mixed $source, mixed $medium): ?string
    {
        $parts = array_filter([
            $this->normalizer->isValidAttributionValue($source) ? $this->normalizer->clean($source) : null,
            $this->normalizer->isValidAttributionValue($medium) ? $this->normalizer->clean($medium) : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function firstValidValue(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if ($this->normalizer->isValidAttributionValue($value)) {
                return $this->normalizer->clean($value);
            }
        }

        return null;
    }

    private function candidateOpportunities(CarbonInterface $start, CarbonInterface $end, Collection $leads): Collection
    {
        $convertedIds = $leads
            ->pluck('converted_opportunity_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $columns = array_values(array_unique(array_merge([
            'salesforce_id',
            'created_date',
            'stage_name',
            'record_type_name',
            'account_phone',
            'account_person_email',
            'account_company_email',
            'reservation',
            'reservation_date',
            'cv_signed',
            'cv_signed_date',
        ], $this->saleAmountResolver->opportunitySelectColumns())));

        $opportunities = DB::table('salesforce_opportunities')
            ->where(function ($query) use ($start, $end): void {
                $query
                    ->whereBetween('created_date', [$start, $end])
                    ->orWhereBetween('reservation_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('cv_signed_date', [$start->toDateString(), $end->toDateString()]);
            })
            ->select($columns)
            ->get()
            ->keyBy('salesforce_id');

        foreach (array_chunk($convertedIds, 500) as $ids) {
            DB::table('salesforce_opportunities')
                ->whereIn('salesforce_id', $ids)
                ->select($columns)
                ->get()
                ->each(function (object $opportunity) use ($opportunities): void {
                    $opportunities[(string) $opportunity->salesforce_id] = $opportunity;
                });
        }

        return $opportunities;
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

            $assignments[(string) $lead->salesforce_id] = [
                'opportunity' => $opportunity,
                'method' => 'converted_opportunity_id',
                'confidence' => 'high',
            ];
            $claimedOpportunityIds[$opportunityId] = true;
        }

        $leadById = $leads->keyBy('salesforce_id');
        $emailIndex = [];
        $phoneIndex = [];

        foreach ($leads as $lead) {
            $key = $this->normalizeEmail($lead->email);
            if ($key !== null) {
                $emailIndex[$key][] = $lead->salesforce_id;
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

            $emailLeadIds = collect()
                ->merge($this->indexedValues($emailIndex, $this->normalizeEmail($opportunity->account_person_email)))
                ->merge($this->indexedValues($emailIndex, $this->normalizeEmail($opportunity->account_company_email)))
                ->filter()
                ->unique()
                ->values();
            $phoneLeadIds = collect($this->indexedValues($phoneIndex, $this->normalizePhone($opportunity->account_phone)))
                ->filter()
                ->unique()
                ->values();

            $candidateRows = $emailLeadIds
                ->map(fn (string $leadId): array => ['lead_id' => $leadId, 'method' => 'account_email_match'])
                ->merge($phoneLeadIds->map(fn (string $leadId): array => ['lead_id' => $leadId, 'method' => 'account_phone_match']))
                ->unique(fn (array $row): string => $row['lead_id'])
                ->map(function (array $row) use ($leadById): ?array {
                    $lead = $leadById->get($row['lead_id']);

                    return $lead ? ['lead' => $lead, 'method' => $row['method']] : null;
                })
                ->filter()
                ->filter(fn (array $row): bool => $this->withinWindow($row['lead']->created_date, $opportunity->created_date, $windowDays))
                ->sortByDesc(fn (array $row): string => sprintf(
                    '%02d|%s',
                    $this->leadAttributionPriority($row['lead']),
                    CarbonImmutable::parse($row['lead']->created_date)->format('YmdHis')
                ))
                ->values();

            $candidateRow = $candidateRows->first();
            $candidate = $candidateRow['lead'] ?? null;

            if (! $candidate) {
                continue;
            }

            $assignments[(string) $candidate->salesforce_id] = [
                'opportunity' => $opportunity,
                'method' => $candidateRows->count() > 1 && $this->leadAttributionPriority($candidate) > 1
                    ? 'account_lead_campaign_match'
                    : $candidateRow['method'],
                'confidence' => $candidateRows->count() > 1 ? 'low' : 'medium',
            ];
            $claimedOpportunityIds[(string) $opportunity->salesforce_id] = true;
        }

        return $assignments;
    }

    private function indexedValues(array $index, ?string $key): array
    {
        return $key === null ? [] : ($index[$key] ?? []);
    }

    private function leadAttributionPriority(object $lead): int
    {
        if ($this->normalizer->isValidAttributionValue($lead->campaign_acquired)) {
            return 3;
        }

        if ($this->normalizer->isValidAttributionValue($lead->acquired_id)
            || $this->normalizer->isValidAttributionValue($lead->content_acquired)) {
            return 2;
        }

        if ($this->normalizer->isValidAttributionValue($lead->fuente_origen)
            || $this->normalizer->isValidAttributionValue($lead->medio_origen)) {
            return 1;
        }

        return 0;
    }

    private function opportunityFlags(object $lead, ?object $opportunity, int $windowDays): array
    {
        if (! $opportunity) {
            return [
                'has_opportunity' => false,
                'has_reservation' => false,
                'has_fallen_reservation' => false,
                'has_sale' => false,
                'reservation_date' => null,
                'sale_date' => null,
                'sale_amount' => null,
            ];
        }

        $stage = (string) $opportunity->stage_name;
        $isClosedLost = strcasecmp($stage, 'Cerrada Perdida') === 0;
        $hasOpportunity = $this->withinWindow($lead->created_date, $opportunity->created_date, $windowDays);
        $hasReservation = (bool) $opportunity->reservation
            && $this->withinWindow($lead->created_date, $opportunity->reservation_date, $windowDays);
        $recordType = $this->normalizer->compactKey($opportunity->record_type_name);
        $candidateSaleAmount = $this->saleAmountResolver->resolve($opportunity);
        $hasSale = (bool) $opportunity->cv_signed
            && ! $isClosedLost
            && ($recordType === 'venta' || ($recordType === 'cambio' && $candidateSaleAmount !== null && $candidateSaleAmount > 0))
            && $this->withinWindow($lead->created_date, $opportunity->cv_signed_date, $windowDays);
        $saleAmount = $hasSale ? $candidateSaleAmount : null;

        if ($recordType === 'cambio' && $saleAmount !== null && $saleAmount < 0) {
            $saleAmount = null;
        }

        return [
            'has_opportunity' => $hasOpportunity,
            'has_reservation' => $hasReservation,
            'has_fallen_reservation' => $hasReservation && $isClosedLost,
            'has_sale' => $hasSale,
            'reservation_date' => $hasReservation ? $opportunity->reservation_date : null,
            'sale_date' => $hasSale ? $opportunity->cv_signed_date : null,
            'sale_amount' => $saleAmount,
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

        if ($digits === '') {
            return null;
        }

        return strlen($digits) >= 9 ? substr($digits, -9) : $digits;
    }

    private function flushAttributions(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('campaign_attributions')->upsert(
            $rows,
            ['lead_id'],
            [
                'opportunity_id',
                'platform',
                'account_id',
                'campaign_id',
                'campaign_name',
                'campaign_name_key',
                'source_acquired',
                'medium_acquired',
                'campaign_acquired',
                'acquired_id',
                'acquired_id_key',
                'content_acquired',
                'content_acquired_key',
                'vehicle_interest',
                'lead_status',
                'lead_created_at',
                'opportunity_created_at',
                'reservation_date',
                'sale_date',
                'sale_amount',
                'has_opportunity',
                'has_reservation',
                'has_fallen_reservation',
                'has_sale',
                'lead_delegation',
                'lead_zone',
                'commercial_user_id',
                'commercial_user_name',
                'attribution_method',
                'attribution_confidence',
                'opportunity_attribution_method',
                'opportunity_attribution_confidence',
                'match_status',
                'campaign_source_type',
                'attribution_window_days',
                'updated_at',
            ]
        );
    }

    private function countCampaignMatch(array &$stats, array $campaign): void
    {
        match ($campaign['method']) {
            'ad_id_match' => $stats['match_ad_id']++,
            'adset_or_adgroup_id_match' => $stats['match_adset_or_adgroup']++,
            'campaign_id_match' => $stats['match_campaign_id']++,
            'campaign_name_match' => $campaign['confidence'] === 'low'
                ? $stats['match_campaign_name_flexible']++
                : $stats['match_campaign_name_exact']++,
            'salesforce_only' => $stats['salesforce_only']++,
            default => null,
        };

        $stats['match_campaign_name'] = $stats['match_campaign_name_exact'] + $stats['match_campaign_name_flexible'];

        match ($campaign['campaign_source_type'] ?? null) {
            'platform_campaign' => $stats['source_type_platform_campaign']++,
            'salesforce_campaign_without_spend' => $stats['source_type_salesforce_campaign_without_spend']++,
            'salesforce_origin' => $stats['source_type_salesforce_origin']++,
            default => null,
        };

        if ($campaign['matched_to_platform']) {
            $stats['matched_to_platform']++;
        }
    }

    private function countLeadAcquisitionShape(array &$stats, object $lead): void
    {
        $hasCampaign = $this->normalizer->isValidAttributionValue($lead->campaign_acquired);
        $hasSourceOrMedium = $this->normalizer->isValidAttributionValue($lead->fuente_origen)
            || $this->normalizer->isValidAttributionValue($lead->medio_origen);

        if ($hasCampaign) {
            $stats['candidates_with_campaign_acquired']++;
        }

        if (! $hasCampaign && $hasSourceOrMedium) {
            $stats['candidates_only_source_medium']++;
        }

        if ($this->normalizer->isValidAttributionValue($lead->acquired_id)) {
            $stats['candidates_with_acquired_id']++;
        }

        if ($this->normalizer->isValidAttributionValue($lead->content_acquired)) {
            $stats['candidates_with_content_acquired']++;
        }
    }

    private function countSaleAmountStats(array &$stats, ?object $opportunity, array $opportunityFlags): void
    {
        if (! $opportunityFlags['has_sale']) {
            return;
        }

        if ($opportunity !== null) {
            $stats['sales_with_opportunity_found']++;
        }

        if ($opportunity !== null && $this->saleAmountResolver->positiveValue($opportunity, 'opo_for_importe_total') !== null) {
            $stats['sales_with_opo_for_importe_total']++;
        }

        if ($opportunity !== null && $this->saleAmountResolver->positiveValue($opportunity, 'amount') !== null) {
            $stats['sales_with_amount']++;
        }

        $saleAmount = $opportunityFlags['sale_amount'];

        if ($saleAmount !== null && (float) $saleAmount > 0) {
            $stats['sales_with_sale_amount']++;
            $stats['sale_amount_sum'] += (float) $saleAmount;
        }
    }

    private function emptyStats(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'range_start' => $start->toDateString(),
            'range_end' => $end->toDateString(),
            'lead_source_table' => 'salesforce_leads',
            'total_leads_in_range' => 0,
            'leads_with_acquisition_not_null' => 0,
            'candidate_leads' => 0,
            'discarded_invalid_values' => 0,
            'discarded_by_date' => 0,
            'processed_leads' => 0,
            'saved_attributions' => 0,
            'matched_to_platform' => 0,
            'match_ad_id' => 0,
            'match_adset_or_adgroup' => 0,
            'match_campaign_id' => 0,
            'match_campaign_name' => 0,
            'match_campaign_name_exact' => 0,
            'match_campaign_name_flexible' => 0,
            'salesforce_only' => 0,
            'source_type_platform_campaign' => 0,
            'source_type_salesforce_campaign_without_spend' => 0,
            'source_type_salesforce_origin' => 0,
            'candidates_with_campaign_acquired' => 0,
            'candidates_only_source_medium' => 0,
            'candidates_with_acquired_id' => 0,
            'candidates_with_content_acquired' => 0,
            'opportunities' => 0,
            'reservations' => 0,
            'fallen_reservations' => 0,
            'sales' => 0,
            'sales_with_opportunity_found' => 0,
            'sales_with_opo_for_importe_total' => 0,
            'sales_with_amount' => 0,
            'sales_with_sale_amount' => 0,
            'sale_amount_sum' => 0.0,
            'sale_amount_field_used' => 'none',
            'duration_seconds' => 0.0,
            'peak_memory_mb' => 0.0,
            'top_campaign_acquired' => [],
            'top_source_medium' => [],
            'top_acquired_id' => [],
            'top_content_acquired' => [],
            'top_platform_spend' => [],
            'warnings' => [],
        ];
    }

    private function topDiagnostics(CarbonInterface $start, CarbonInterface $end, int $windowDays): array
    {
        $attributions = DB::table('campaign_attributions')
            ->where('lead_created_at', '>=', $start)
            ->where('lead_created_at', '<', $end)
            ->where('attribution_window_days', $windowDays);

        return [
            'top_campaign_acquired' => $this->topAttributionValues(clone $attributions, ['campaign_acquired']),
            'top_source_medium' => $this->topAttributionValues(clone $attributions, ['source_acquired', 'medium_acquired']),
            'top_acquired_id' => $this->topAttributionValues(clone $attributions, ['acquired_id']),
            'top_content_acquired' => $this->topAttributionValues(clone $attributions, ['content_acquired']),
            'top_platform_spend' => $this->topPlatformSpend($start, $end),
        ];
    }

    private function topAttributionValues($query, array $columns): array
    {
        foreach ($columns as $column) {
            $query->whereNotNull($column)->where($column, '<>', '');
        }

        return $query
            ->select(array_merge($columns, [DB::raw('COUNT(*) as total')]))
            ->groupBy(...$columns)
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(function (object $row) use ($columns): array {
                $label = implode(' + ', array_map(fn (string $column) => (string) ($row->{$column} ?? ''), $columns));

                return ['valor' => $label, 'total' => (int) $row->total];
            })
            ->all();
    }

    private function topPlatformSpend(CarbonInterface $start, CarbonInterface $end): array
    {
        return DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $start->toDateString())
            ->where('metric_date', '<=', CarbonImmutable::parse($end)->toDateString())
            ->select([
                'platform',
                'campaign_id',
                'campaign_name',
                DB::raw('SUM(COALESCE(spend, 0)) as spend'),
            ])
            ->groupBy('platform', 'campaign_id', 'campaign_name')
            ->orderByDesc('spend')
            ->limit(20)
            ->get()
            ->map(fn (object $row): array => [
                'plataforma' => (string) $row->platform,
                'campaign_id' => (string) $row->campaign_id,
                'campaign_name' => (string) $row->campaign_name,
                'spend' => round((float) $row->spend, 2),
            ])
            ->all();
    }

    private function invalidateCache(): void
    {
        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
    }
}
