<?php

namespace App\Services\Reports\ReservationsSales\Sync;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class SalesforceOpportunitySyncService
{
    private const SYNC_CHUNK_DAYS = 7;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly OpportunityPortalNormalizer $portalNormalizer,
    ) {
    }

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $saved = 0;
        $soqls = [];
        $seen = [];
        $includeCompanyEmail = true;
        $stats = [
            'opportunity' => 0,
            'lead' => 0,
            'opportunity_source' => 0,
            'fallback_exposicion' => 0,
            'fallback_web' => 0,
            'unclassified' => 0,
            'reservas_vivas' => 0,
            'caidas' => 0,
            'cv_firmados' => 0,
        ];

        $chunkStart = CarbonImmutable::parse($periodStart);
        $finalEnd = CarbonImmutable::parse($periodEnd);

        while ($chunkStart->lessThan($finalEnd)) {
            $chunkEnd = $chunkStart->addDays(self::SYNC_CHUNK_DAYS)->min($finalEnd);
            $soql = $this->buildSoql($chunkStart, $chunkEnd, $includeCompanyEmail);
            $records = $this->queryOpportunities($soql, $chunkStart, $chunkEnd, $includeCompanyEmail);
            $soqls[] = $soql;

            $records = collect($records)
                ->filter(fn (array $record) => filled(data_get($record, 'Id')))
                ->reject(function (array $record) use (&$seen): bool {
                    $id = (string) data_get($record, 'Id');

                    if (isset($seen[$id])) {
                        return true;
                    }

                    $seen[$id] = true;

                    return false;
                })
                ->values()
                ->all();

            $leadMatches = $this->relatedLeadMatches($records);

            foreach ($records as $record) {
                $this->saveRecord($record, $leadMatches, $stats);
                $saved++;
            }

            $chunkStart = $chunkEnd;
        }

        return [
            'soql' => implode("\n\n-- chunk --\n\n", $soqls),
            'queried' => count($seen),
            'saved' => $saved,
            'stats' => $stats,
        ];
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->buildSoql($periodStart, $periodEnd, true);
    }

    public function testSoql(): string
    {
        return <<<'SOQL'
SELECT Id, Name, CreatedDate, StageName FROM Opportunity WHERE IsDeleted = false ORDER BY CreatedDate DESC LIMIT 5
SOQL;
    }

    public function resolvePortalForRecord(array $opportunity, Collection $leads): array
    {
        return $this->resolvePortal($opportunity, $leads);
    }

    public function relatedLeadMatchesForOpportunities(array $opportunities): Collection
    {
        return $this->relatedLeadMatches($opportunities);
    }

    private function buildSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd, bool $includeCompanyEmail): string
    {
        $startDateTime = $this->soqlDateTime($periodStart);
        $endDateTime = $this->soqlDateTime($periodEnd);
        $startDate = CarbonImmutable::parse($periodStart)->utc()->toDateString();
        $endDate = CarbonImmutable::parse($periodEnd)->utc()->toDateString();
        $companyEmailSelect = $includeCompanyEmail ? "    Account.AC_C_EMA_email__c,\n" : '';

        return <<<SOQL
SELECT
    Id,
    Name,
    CreatedDate,
    CloseDate,
    Amount,
    StageName,
    RecordType.Name,
    OwnerId,
    Owner.Name,
    Owner.USR_SEL_Delegacion__c,
    AccountId,
    Account.Name,
    Account.Phone,
    Account.PersonEmail,
{$companyEmailSelect}    Portal__c,
    Fuente_de_Origen__c,
    OPO_CAS_Reserva__c,
    OPO_FEC_Fecha_de_reserva__c,
    OPO_CAS_Contrato_CV_firmado__c,
    Fecha_firma_contrato__c
FROM Opportunity
WHERE
    IsDeleted = false
    AND (
        (CreatedDate >= {$startDateTime} AND CreatedDate < {$endDateTime})
        OR (OPO_FEC_Fecha_de_reserva__c >= {$startDate} AND OPO_FEC_Fecha_de_reserva__c < {$endDate})
        OR (Fecha_firma_contrato__c >= {$startDate} AND Fecha_firma_contrato__c < {$endDate})
    )
SOQL;
    }

    private function queryOpportunities(
        string &$soql,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        bool &$includeCompanyEmail,
    ): array {
        try {
            return $this->client->query($soql);
        } catch (RuntimeException $exception) {
            if (! str_contains($exception->getMessage(), 'AC_C_EMA_email__c')) {
                throw $exception;
            }

            $includeCompanyEmail = false;
            $soql = $this->buildSoql($periodStart, $periodEnd, false);

            return $this->client->query($soql);
        }
    }

    private function saveRecord(array $record, Collection $leadMatches, array &$stats): void
    {
        $portal = $this->resolvePortal($record, $leadMatches);
        $source = $this->portalNormalizer->normalize(data_get($record, 'Fuente_de_Origen__c'));
        $stage = (string) data_get($record, 'StageName', '');
        $isClosedLost = strcasecmp($stage, 'Cerrada Perdida') === 0;
        $reservation = (bool) data_get($record, 'OPO_CAS_Reserva__c', false);
        $cvSigned = (bool) data_get($record, 'OPO_CAS_Contrato_CV_firmado__c', false);

        SalesforceOpportunity::updateOrCreate(
            ['salesforce_id' => data_get($record, 'Id')],
            [
                'name' => data_get($record, 'Name'),
                'created_date' => $this->parseDateTime(data_get($record, 'CreatedDate')),
                'close_date' => data_get($record, 'CloseDate'),
                'amount' => data_get($record, 'Amount'),
                'stage_name' => $stage,
                'record_type_name' => data_get($record, 'RecordType.Name'),
                'owner_id' => data_get($record, 'OwnerId'),
                'owner_name' => data_get($record, 'Owner.Name'),
                'owner_delegation' => data_get($record, 'Owner.USR_SEL_Delegacion__c'),
                'account_id' => data_get($record, 'AccountId'),
                'account_name' => data_get($record, 'Account.Name'),
                'account_phone' => data_get($record, 'Account.Phone'),
                'account_person_email' => data_get($record, 'Account.PersonEmail'),
                'account_company_email' => data_get($record, 'Account.AC_C_EMA_email__c'),
                'portal_original' => data_get($record, 'Portal__c'),
                'opportunity_source_raw' => data_get($record, 'Fuente_de_Origen__c'),
                'opportunity_source_normalized' => $source['portal'],
                'portal_resolved' => $portal['portal'],
                'portal_resolution_source' => $portal['source'],
                'portal_resolution_lead_id' => $portal['lead_id'],
                'portal_resolution_debug' => $portal['debug'],
                'reservation' => $reservation,
                'reservation_date' => data_get($record, 'OPO_FEC_Fecha_de_reserva__c'),
                'cv_signed' => $cvSigned,
                'cv_signed_date' => data_get($record, 'Fecha_firma_contrato__c'),
                'raw_payload' => $record,
            ]
        );

        $stats[$portal['source']]++;
        $stats['reservas_vivas'] += $reservation && ! $cvSigned && ! $isClosedLost ? 1 : 0;
        $stats['caidas'] += $isClosedLost ? 1 : 0;
        $stats['cv_firmados'] += $cvSigned && ! $isClosedLost ? 1 : 0;
    }

    private function relatedLeadMatches(array $opportunities): Collection
    {
        $emails = collect();
        $phones = collect();

        foreach ($opportunities as $record) {
            foreach ([data_get($record, 'Account.PersonEmail'), data_get($record, 'Account.AC_C_EMA_email__c')] as $email) {
                if (filled($email)) {
                    $emails->push(Str::lower(trim((string) $email)));
                }
            }

            if (filled(data_get($record, 'Account.Phone'))) {
                $phones->push(trim((string) data_get($record, 'Account.Phone')));
            }
        }

        $matches = collect();
        $emailValues = $emails->unique()->values();
        $phoneValues = $phones->unique()->values();

        foreach ($emailValues->chunk(80) as $chunk) {
            $matches = $matches->merge($this->queryLeads($chunk->all(), []));
        }

        foreach ($phoneValues->chunk(80) as $chunk) {
            $matches = $matches->merge($this->queryLeads([], $chunk->all()));
        }

        return $matches
            ->sortByDesc(fn (array $lead) => data_get($lead, 'CreatedDate'))
            ->values();
    }

    private function queryLeads(array $emails, array $phones): array
    {
        $clauses = [];

        if ($emails !== []) {
            $in = implode(', ', array_map(fn (string $value) => "'".$this->escape($value)."'", $emails));
            $clauses[] = "Email IN ({$in})";
        }

        if ($phones !== []) {
            $in = implode(', ', array_map(fn (string $value) => "'".$this->escape($value)."'", $phones));
            $clauses[] = "Phone IN ({$in})";
            $clauses[] = "MobilePhone IN ({$in})";
        }

        if ($clauses === []) {
            return [];
        }

        $where = implode(' OR ', $clauses);

        return $this->client->query(<<<SOQL
SELECT
    Id,
    Name,
    CreatedDate,
    Phone,
    MobilePhone,
    Email,
    Portal_Text__c,
    LEA_SEL_Fuente_Origen__c,
    Fuente_Nuevo__c
FROM Lead
WHERE
    IsDeleted = false
    AND ({$where})
ORDER BY CreatedDate DESC
SOQL);
    }

    private function resolvePortal(array $opportunity, Collection $leads): array
    {
        $rawPortal = $this->portalNormalizer->clean(data_get($opportunity, 'Portal__c'));
        $normalizedPortal = $this->portalNormalizer->normalize($rawPortal);
        $sourceRaw = $this->portalNormalizer->clean(data_get($opportunity, 'Fuente_de_Origen__c'));
        $sourceNormalized = $this->portalNormalizer->normalize($sourceRaw);
        $debug = [
            'rawPortal' => $rawPortal,
            'normalizedPortal' => $normalizedPortal['portal'],
            'opportunitySourceRaw' => $sourceRaw,
            'opportunitySourceNormalized' => $sourceNormalized['portal'],
            'selectedLeadId' => null,
            'selectedLeadPortalRaw' => null,
            'reason' => null,
        ];

        if ($normalizedPortal['is_valid_final'] && $normalizedPortal['is_conclusive']) {
            $debug['reason'] = 'opportunity_portal_conclusive';

            return ['portal' => $normalizedPortal['portal'], 'source' => 'opportunity', 'lead_id' => null, 'debug' => $debug];
        }

        $emails = collect([data_get($opportunity, 'Account.PersonEmail'), data_get($opportunity, 'Account.AC_C_EMA_email__c')])
            ->filter()
            ->map(fn ($email) => Str::lower(trim((string) $email)))
            ->values()
            ->all();
        $phone = $this->normalizePhone(data_get($opportunity, 'Account.Phone'));

        $candidate = $leads
            ->filter(function (array $lead) use ($emails, $phone): bool {
                $emailMatch = filled(data_get($lead, 'Email')) && in_array(Str::lower(trim((string) data_get($lead, 'Email'))), $emails, true);
                $phoneMatch = $phone !== null && in_array($phone, [
                    $this->normalizePhone(data_get($lead, 'Phone')),
                    $this->normalizePhone(data_get($lead, 'MobilePhone')),
                ], true);

                return $emailMatch || $phoneMatch;
            })
            ->first(fn (array $lead): bool => $this->portalNormalizer->isValidForLead($this->leadPortal($lead)));

        if ($candidate) {
            $leadPortalRaw = $this->leadPortal($candidate);
            $leadPortal = $this->portalNormalizer->normalize($leadPortalRaw);
            $debug['selectedLeadId'] = data_get($candidate, 'Id');
            $debug['selectedLeadPortalRaw'] = $leadPortalRaw;
            $debug['reason'] = 'lead_related_valid_portal';

            return [
                'portal' => $leadPortal['portal'],
                'source' => 'lead',
                'lead_id' => data_get($candidate, 'Id'),
                'debug' => array_merge($debug, ['lead_created_date' => data_get($candidate, 'CreatedDate')]),
            ];
        }

        if ($this->portalNormalizer->isUsefulSource($sourceRaw)) {
            $debug['reason'] = 'opportunity_source_valid';

            return ['portal' => $sourceNormalized['portal'], 'source' => 'opportunity_source', 'lead_id' => null, 'debug' => $debug];
        }

        if ($this->portalNormalizer->isFallbackExpositionRaw($rawPortal)) {
            $debug['reason'] = 'fallback_exposicion_no_alternative';

            return ['portal' => OpportunityPortalNormalizer::EXPOSITION, 'source' => 'fallback_exposicion', 'lead_id' => null, 'debug' => $debug];
        }

        if ($this->portalNormalizer->isFallbackWebRaw($rawPortal)) {
            $debug['reason'] = 'fallback_web_non_conclusive_portal';

            return ['portal' => OpportunityPortalNormalizer::WEB, 'source' => 'fallback_web', 'lead_id' => null, 'debug' => $debug];
        }

        $debug['reason'] = 'unclassified_no_valid_source';

        return ['portal' => OpportunityPortalNormalizer::UNCLASSIFIED, 'source' => 'unclassified', 'lead_id' => null, 'debug' => $debug];
    }

    private function leadPortal(array $lead): ?string
    {
        return $this->portalNormalizer->clean(data_get($lead, 'Portal_Text__c'))
            ?? $this->portalNormalizer->clean(data_get($lead, 'LEA_SEL_Fuente_Origen__c'))
            ?? $this->portalNormalizer->clean(data_get($lead, 'Fuente_Nuevo__c'));
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value);
    }

    private function normalizePhone(mixed $value): ?string
    {
        $value = preg_replace('/\D+/', '', (string) $value);
        $value = preg_replace('/^34(?=\d{9}$)/', '', $value ?? '');

        return $value !== '' ? $value : null;
    }

    private function escape(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }
}
