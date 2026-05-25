<?php

namespace App\Services\Reports\Calls;

use App\Models\SalesforceCall;
use App\Models\SalesforceUser;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SalesforceCallSyncService
{
    private const SYNC_CHUNK_DAYS = 7;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly CallDescriptionParser $parser,
        private readonly CallPortalNormalizer $portalNormalizer,
        private readonly CallAgentResolver $agentResolver,
    ) {
    }

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $this->syncUsers();

        $saved = 0;
        $queried = 0;
        $soqls = [];
        $stats = [
            'answered' => 0,
            'not_answered' => 0,
            'inbound' => 0,
            'outbound' => 0,
            'commercial_direct' => 0,
            'switchboard' => 0,
            'portal' => 0,
            'teams' => [],
        ];

        $chunkStart = CarbonImmutable::parse($periodStart);
        $finalEnd = CarbonImmutable::parse($periodEnd);

        while ($chunkStart->lessThan($finalEnd)) {
            $chunkEnd = $chunkStart->addDays(self::SYNC_CHUNK_DAYS)->min($finalEnd);
            $soql = $this->soql($chunkStart, $chunkEnd);
            $records = $this->client->query($soql);
            $soqls[] = $soql;
            $queried += count($records);
            $leadMatches = $this->relatedLeadMatches($records);

            foreach ($records as $record) {
                if (blank(data_get($record, 'Id'))) {
                    continue;
                }

                $call = $this->saveRecord($record, $leadMatches);
                $saved++;
                $this->addStats($stats, $call);
            }

            $chunkStart = $chunkEnd;
        }

        $this->invalidateDashboardCache();

        return [
            'soql' => implode("\n\n-- chunk --\n\n", $soqls),
            'queried' => $queried,
            'saved' => $saved,
            'stats' => $stats,
        ];
    }

    public function syncUsers(): array
    {
        $soql = <<<'SOQL'
SELECT
    Id,
    Name,
    IsActive,
    Profile.Name,
    USR_SEL_Delegacion__c
FROM User
WHERE
    IsActive = true
SOQL;

        $records = $this->client->query($soql);
        $saved = 0;

        foreach ($records as $record) {
            if (blank(data_get($record, 'Id'))) {
                continue;
            }

            SalesforceUser::updateOrCreate(
                ['salesforce_id' => data_get($record, 'Id')],
                [
                    'name' => data_get($record, 'Name'),
                    'profile_name' => data_get($record, 'Profile.Name'),
                    'user_delegation' => data_get($record, 'USR_SEL_Delegacion__c'),
                    'is_active' => (bool) data_get($record, 'IsActive', true),
                    'raw_payload' => $record,
                ]
            );

            $saved++;
        }

        return ['soql' => $soql, 'queried' => count($records), 'saved' => $saved];
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $startDateTime = $this->soqlDateTime($periodStart);
        $endDateTime = $this->soqlDateTime($periodEnd);

        return <<<SOQL
SELECT
    Id,
    Subject,
    Description,
    Type,
    Status,
    Priority,
    ActivityDate,
    CreatedDate,
    LastModifiedDate,
    OwnerId,
    Owner.Name,
    Owner.Profile.Name,
    WhoId,
    WhatId,
    CallObject,
    CallDurationInSeconds,
    CallType,
    Portales__c
FROM Task
WHERE
    IsDeleted = false
    AND Type = 'Call'
    AND CallObject != null
    AND CreatedDate >= {$startDateTime}
    AND CreatedDate < {$endDateTime}
ORDER BY CreatedDate DESC
SOQL;
    }

    public function classifyStatus(?string $resultRaw): string
    {
        $result = Str::of((string) $resultRaw)->upper()->trim()->toString();

        return $result === 'ANSWERED' ? 'answered' : 'not_answered';
    }

    public function classifyDirection(?string $callType, ?string $subject, ?string $descriptionType): string
    {
        $haystack = Str::of(trim((string) $callType.' '.(string) $subject.' '.(string) $descriptionType))
            ->lower()
            ->ascii()
            ->toString();

        if (str_contains($haystack, 'inbound') || str_contains($haystack, 'entrante')) {
            return 'inbound';
        }

        if (str_contains($haystack, 'outbound') || str_contains($haystack, 'saliente')) {
            return 'outbound';
        }

        return 'unknown';
    }

    public function adjustedDuration(?int $duration, string $origin): int
    {
        $duration = max(0, (int) $duration);
        $subtract = $origin === 'commercial_direct' ? 5 : 10;

        return max(0, $duration - $subtract);
    }

    private function saveRecord(array $record, Collection $leadMatches): SalesforceCall
    {
        $parsed = $this->parser->parse(data_get($record, 'Description'));
        $portal = $this->resolvePortal($record, $leadMatches);
        $duration = $this->duration(data_get($record, 'CallDurationInSeconds'), $parsed['parsed_duration_seconds']);
        $callStatus = $this->classifyStatus($parsed['result_raw'] ?? null);
        $direction = $this->classifyDirection(data_get($record, 'CallType'), data_get($record, 'Subject'), $parsed['type_raw'] ?? null);
        $agent = $this->agentResolver->resolve([
            'id' => data_get($record, 'OwnerId'),
            'name' => data_get($record, 'Owner.Name'),
            'profile_name' => data_get($record, 'Owner.Profile.Name'),
        ], $parsed, $portal['origin']);

        return SalesforceCall::updateOrCreate(
            ['salesforce_id' => data_get($record, 'Id')],
            [
                'subject' => data_get($record, 'Subject'),
                'description' => data_get($record, 'Description'),
                'type' => data_get($record, 'Type'),
                'status' => data_get($record, 'Status'),
                'priority' => data_get($record, 'Priority'),
                'activity_date' => data_get($record, 'ActivityDate'),
                'created_date' => $this->parseDateTime(data_get($record, 'CreatedDate')),
                'last_modified_date' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
                'owner_id' => data_get($record, 'OwnerId'),
                'owner_name' => data_get($record, 'Owner.Name'),
                'owner_profile_name' => data_get($record, 'Owner.Profile.Name'),
                'who_id' => data_get($record, 'WhoId'),
                'who_type' => $this->whoType(data_get($record, 'WhoId')),
                'what_id' => data_get($record, 'WhatId'),
                'call_object' => data_get($record, 'CallObject'),
                'call_duration_seconds' => is_numeric(data_get($record, 'CallDurationInSeconds')) ? (int) data_get($record, 'CallDurationInSeconds') : null,
                'parsed_duration_seconds' => $parsed['parsed_duration_seconds'],
                'adjusted_duration_seconds' => $this->adjustedDuration($duration, $portal['origin']),
                'call_type_raw' => data_get($record, 'CallType'),
                'direction' => $direction,
                'portales_raw' => data_get($record, 'Portales__c'),
                'call_origin' => $portal['origin'],
                'portal_resolved' => $portal['portal'],
                'portal_resolution_source' => $portal['source'],
                'result_raw' => $parsed['result_raw'],
                'call_status' => $callStatus,
                'is_answered' => $callStatus === 'answered',
                'is_lost' => $callStatus !== 'answered',
                'fixed_phone' => $parsed['fixed_phone'],
                'client_phone' => $parsed['client_phone'],
                'destination_raw' => $parsed['destination_raw'],
                'destination_agent_code' => $parsed['destination_agent_code'],
                'destination_agent_name' => $parsed['destination_agent_name'],
                'operational_user_id' => $agent['operational_user_id'],
                'operational_user_name' => $agent['operational_user_name'],
                'operational_team' => $agent['operational_team'],
                'owner_team' => $agent['owner_team'],
                'delegation' => $agent['delegation'],
                'zone' => $agent['zone'],
                'queue_raw' => $parsed['queue_raw'],
                'uid_raw' => $parsed['uid_raw'],
                'puid_raw' => $parsed['puid_raw'],
                'call_started_at' => $parsed['call_started_at'],
                'call_ended_at' => $parsed['call_ended_at'],
                'raw_payload' => $record,
                'parse_debug' => [
                    'parsed' => $parsed,
                    'portal_debug' => $portal['debug'] ?? [],
                ],
            ]
        );
    }

    private function resolvePortal(array $record, Collection $leadMatches): array
    {
        $portal = $this->portalNormalizer->normalize(data_get($record, 'Portales__c'));
        $debug = ['portales_raw' => data_get($record, 'Portales__c'), 'lead_id' => null, 'lead_portal_raw' => null];

        if (data_get($record, 'Portales__c') !== null && $portal['portal'] === CallPortalNormalizer::UNCLASSIFIED) {
            $lead = $leadMatches->get(data_get($record, 'WhoId'));
            $leadPortalRaw = $this->leadPortal($lead);
            $leadPortal = $this->portalNormalizer->normalizeLeadPortal($leadPortalRaw);

            if ($leadPortal['portal'] !== CallPortalNormalizer::UNCLASSIFIED) {
                $portal['portal'] = $leadPortal['portal'];
                $portal['source'] = 'lead';
            }

            $debug['lead_id'] = data_get($lead, 'Id');
            $debug['lead_portal_raw'] = $leadPortalRaw;
        }

        $portal['debug'] = $debug;

        return $portal;
    }

    private function relatedLeadMatches(array $records): Collection
    {
        $ids = collect($records)
            ->pluck('WhoId')
            ->filter(fn ($id) => is_string($id) && str_starts_with($id, '00Q'))
            ->unique()
            ->values();

        $leads = collect();

        foreach ($ids->chunk(80) as $chunk) {
            $in = $chunk->map(fn (string $id) => "'".$this->escape($id)."'")->implode(', ');
            $leads = $leads->merge($this->client->query(<<<SOQL
SELECT
    Id,
    Name,
    CreatedDate,
    Portal_Text__c,
    LEA_SEL_Fuente_Origen__c,
    Fuente_Nuevo__c,
    Medio_Nuevo__c,
    Delegacion_Encargada_Text__c,
    Delegacion_Encargada__c,
    Delegacion_Encargada_Bueno__c
FROM Lead
WHERE Id IN ({$in})
SOQL));
        }

        return $leads->keyBy('Id');
    }

    private function leadPortal(mixed $lead): ?string
    {
        if (! is_array($lead)) {
            return null;
        }

        return $this->portalNormalizer->clean(data_get($lead, 'Portal_Text__c'))
            ?? $this->portalNormalizer->clean(data_get($lead, 'LEA_SEL_Fuente_Origen__c'))
            ?? $this->portalNormalizer->clean(data_get($lead, 'Fuente_Nuevo__c'));
    }

    private function duration(mixed $callDuration, ?int $parsedDuration): int
    {
        if (is_numeric($callDuration) && (int) $callDuration >= 0) {
            return (int) $callDuration;
        }

        return max(0, (int) $parsedDuration);
    }

    private function whoType(mixed $whoId): string
    {
        $whoId = (string) $whoId;

        if ($whoId === '') {
            return 'Unknown';
        }

        if (str_starts_with($whoId, '00Q')) {
            return 'Lead';
        }

        if (str_starts_with($whoId, '003')) {
            return 'Contact';
        }

        return 'Other';
    }

    private function addStats(array &$stats, SalesforceCall $call): void
    {
        $stats[$call->is_answered ? 'answered' : 'not_answered']++;
        $stats[$call->direction] = ($stats[$call->direction] ?? 0) + 1;
        $stats[$call->call_origin] = ($stats[$call->call_origin] ?? 0) + 1;
        $stats['teams'][$call->operational_team] = ($stats['teams'][$call->operational_team] ?? 0) + 1;
    }

    private function invalidateDashboardCache(): void
    {
        Cache::forever('salesforce_calls_dashboard_cache_version', ((int) Cache::get('salesforce_calls_dashboard_cache_version', 1)) + 1);
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value);
    }

    private function escape(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }
}
