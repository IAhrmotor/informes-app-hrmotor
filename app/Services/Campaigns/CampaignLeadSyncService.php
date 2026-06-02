<?php

namespace App\Services\Campaigns;

use App\Models\CampaignSalesforceLead;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CampaignLeadSyncService
{
    private const UPSERT_CHUNK_SIZE = 500;

    public function __construct(
        private readonly SalesforceClient $client,
    ) {
    }

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd, bool $fresh = false): array
    {
        $start = CarbonImmutable::parse($periodStart)->startOfDay();
        $end = CarbonImmutable::parse($periodEnd);
        $deleted = 0;

        if ($fresh) {
            $deleted = CampaignSalesforceLead::query()
                ->where('created_date', '>=', $start)
                ->where('created_date', '<', $end)
                ->delete();
        }

        $warnings = [];

        try {
            $records = $this->client->query($this->soql($start, $end));
        } catch (RuntimeException $exception) {
            $warnings[] = 'La query filtrada de Lead de campana fallo. Se consulta por rango y se filtra en PHP. Error: '.$exception->getMessage();
            $records = $this->client->query($this->rangeSoql($start, $end));
        }

        $rows = [];
        $stats = [
            'table' => 'campaign_salesforce_leads',
            'deleted' => $deleted,
            'queried' => count($records),
            'saved' => 0,
            'with_campaign_acquired' => 0,
            'with_acquired_id' => 0,
            'with_content_acquired' => 0,
            'with_fuente_origen' => 0,
            'with_medio_origen' => 0,
            'without_acquisition' => 0,
            'warnings' => $warnings,
        ];
        $now = now();

        foreach ($records as $record) {
            if (! is_array($record) || blank($this->value($record, 'Id'))) {
                continue;
            }

            $row = $this->mapRecord($record, $now);
            $hasAcquisition = $this->countMappedRecord($stats, $row);

            if (! $hasAcquisition) {
                continue;
            }

            $rows[] = $row;

            if (count($rows) >= self::UPSERT_CHUNK_SIZE) {
                $stats['saved'] += $this->upsert($rows);
                $rows = [];
            }
        }

        $stats['saved'] += $this->upsert($rows);

        return $stats;
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->leadSoql($periodStart, $periodEnd, true);
    }

    public function rangeSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->leadSoql($periodStart, $periodEnd, false);
    }

    private function leadSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd, bool $filterAcquisition): string
    {
        $start = CarbonImmutable::parse($periodStart)->utc()->format('Y-m-d\TH:i:s\Z');
        $end = CarbonImmutable::parse($periodEnd)->utc()->format('Y-m-d\TH:i:s\Z');
        $campaignWhere = $filterAcquisition
            ? <<<'SOQL'
    AND (
        Campa_a_Adquirida__c != null
        OR Id_Adquirido__c != null
        OR Contenido_Adquirido__c != null
        OR LEA_SEL_Fuente_Origen__c != null
        OR LEA_SEL_Medio_Origen__c != null
    )
SOQL
            : '';

        return <<<SOQL
SELECT
    Id,
    CreatedDate,
    Name,
    Status,
    OwnerId,
    Owner.Name,
    Phone,
    MobilePhone,
    Email,
    IsConverted,
    ConvertedDate,
    ConvertedAccountId,
    ConvertedContactId,
    ConvertedOpportunityId,
    LEA_SEL_Fuente_Origen__c,
    LEA_SEL_Medio_Origen__c,
    Campa_a_Adquirida__c,
    Id_Adquirido__c,
    Contenido_Adquirido__c,
    LEA_BUS_Vehiculo_de_interes__c,
    Delegacion_Encargada_Text__c,
    Delegacion_Encargada__c,
    Delegacion_Encargada_Bueno__c
FROM Lead
WHERE
    IsDeleted = false
    AND CreatedDate >= {$start}
    AND CreatedDate < {$end}
{$campaignWhere}
SOQL;
    }

    public function mapRecord(array $record, mixed $now = null): array
    {
        $now ??= now();

        return [
            'salesforce_id' => $this->value($record, 'Id'),
            'name' => $this->value($record, 'Name'),
            'created_date' => $this->parseDateTime($this->value($record, 'CreatedDate')),
            'status' => $this->value($record, 'Status'),
            'owner_id' => $this->value($record, 'OwnerId'),
            'owner_name' => $this->value($record, 'Owner.Name'),
            'phone' => $this->value($record, 'Phone'),
            'mobile_phone' => $this->value($record, 'MobilePhone'),
            'email' => $this->value($record, 'Email'),
            'is_converted' => (bool) $this->value($record, 'IsConverted'),
            'converted_date' => $this->parseDateTime($this->value($record, 'ConvertedDate')),
            'converted_account_id' => $this->value($record, 'ConvertedAccountId'),
            'converted_contact_id' => $this->value($record, 'ConvertedContactId'),
            'converted_opportunity_id' => $this->value($record, 'ConvertedOpportunityId'),
            'fuente_origen' => $this->value($record, 'LEA_SEL_Fuente_Origen__c'),
            'medio_origen' => $this->value($record, 'LEA_SEL_Medio_Origen__c'),
            'campaign_acquired' => $this->value($record, 'Campa_a_Adquirida__c'),
            'acquired_id' => $this->value($record, 'Id_Adquirido__c'),
            'content_acquired' => $this->value($record, 'Contenido_Adquirido__c'),
            'vehicle_interest' => $this->value($record, 'LEA_BUS_Vehiculo_de_interes__c'),
            'delegacion_encargada_text' => $this->value($record, 'Delegacion_Encargada_Text__c'),
            'delegacion_encargada_id' => $this->value($record, 'Delegacion_Encargada__c'),
            'delegacion_encargada_bueno' => $this->value($record, 'Delegacion_Encargada_Bueno__c'),
            'raw_payload' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function value(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($row, $key);

            if ($value !== null) {
                return $value;
            }
        }

        $lower = [];

        foreach (Arr::dot($row) as $key => $value) {
            $lower[mb_strtolower((string) $key)] = $value;
        }

        foreach ($keys as $key) {
            $lowerKey = mb_strtolower($key);

            if (array_key_exists($lowerKey, $lower)) {
                return $lower[$lowerKey];
            }
        }

        return null;
    }

    private function upsert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::table('campaign_salesforce_leads')->upsert(
            $rows,
            ['salesforce_id'],
            [
                'name',
                'created_date',
                'status',
                'owner_id',
                'owner_name',
                'phone',
                'mobile_phone',
                'email',
                'is_converted',
                'converted_date',
                'converted_account_id',
                'converted_contact_id',
                'converted_opportunity_id',
                'fuente_origen',
                'medio_origen',
                'campaign_acquired',
                'acquired_id',
                'content_acquired',
                'vehicle_interest',
                'delegacion_encargada_text',
                'delegacion_encargada_id',
                'delegacion_encargada_bueno',
                'raw_payload',
                'updated_at',
            ]
        );

        return count($rows);
    }

    private function countMappedRecord(array &$stats, array $row): bool
    {
        $hasAny = false;

        foreach ([
            'with_campaign_acquired' => 'campaign_acquired',
            'with_acquired_id' => 'acquired_id',
            'with_content_acquired' => 'content_acquired',
            'with_fuente_origen' => 'fuente_origen',
            'with_medio_origen' => 'medio_origen',
        ] as $counter => $field) {
            if (filled(trim((string) ($row[$field] ?? '')))) {
                $stats[$counter]++;
                $hasAny = true;
            }
        }

        if (! $hasAny) {
            $stats['without_acquisition']++;
        }

        return $hasAny;
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
