<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoGa4OrganicDailyMetric;
use App\Models\SeoGa4OrganicKeyEventDailyMetric;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class Ga4OrganicConversionSyncService
{
    public const DATASET = 'seo_ga4_organic_conversions';

    private const PAGE_SIZE = 10000;

    public function __construct(
        private readonly GoogleAnalyticsClient $client,
        private readonly Ga4MetricDecimalNormalizer $metricNormalizer,
    ) {}

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public function configuredPropertyId(): ?string
    {
        return $this->client->configuredPropertyId();
    }

    /** @return array<string, mixed> */
    public function sync(int $days): array
    {
        $propertyId = (string) $this->configuredPropertyId();
        $propertyName = 'properties/'.$propertyId;
        $property = $this->client->property();
        if (($property['name'] ?? null) !== $propertyName) {
            throw new RuntimeException('Google Analytics no permite verificar la property configurada.');
        }

        $timezone = $this->validatedTimezone($property['timeZone'] ?? null);
        $streams = $this->client->dataStreams();
        $webStreamCount = collect($streams)->where('type', 'WEB_DATA_STREAM')->count();
        if ($webStreamCount === 0) {
            throw new RuntimeException('Google Analytics no tiene ningun web stream configurado.');
        }

        $keyEvents = $this->client->keyEvents();
        if ($keyEvents === []) {
            throw new RuntimeException('Google Analytics no tiene Key Events configurados.');
        }

        $cutoff = CarbonImmutable::now($timezone)->startOfDay()->subDays($this->reportingLagDays());
        $start = $cutoff->subDays($days - 1);
        $reportCalls = 0;
        $globalRows = $this->reportRows($this->dailyPayload($start, $cutoff), $reportCalls);
        $spainRows = $this->reportRows($this->dailyPayload($start, $cutoff, true), $reportCalls);
        $eventRows = $this->reportRows($this->eventPayload($start, $cutoff), $reportCalls);
        $extractedAt = now();

        $dailyRows = [
            ...$this->dailyDatabaseRows($globalRows, $propertyId, 'ALL', $timezone, $start, $cutoff, $extractedAt),
            ...$this->dailyDatabaseRows($spainRows, $propertyId, 'ESP', $timezone, $start, $cutoff, $extractedAt),
        ];
        $eventDatabaseRows = $this->eventDatabaseRows($eventRows, $propertyId, $timezone, $start, $cutoff, $extractedAt);

        DB::transaction(function () use ($dailyRows, $eventDatabaseRows, $propertyId, $start, $cutoff): void {
            foreach (array_chunk($dailyRows, 500) as $chunk) {
                SeoGa4OrganicDailyMetric::query()->upsert(
                    $chunk,
                    ['property_id', 'data_date', 'country_scope'],
                    ['key_events', 'source_timezone', 'extracted_at', 'updated_at'],
                );
            }

            SeoGa4OrganicKeyEventDailyMetric::query()
                ->where('property_id', $propertyId)
                ->where('country_scope', 'ESP')
                ->where('data_date', '>=', $start->toDateString())
                ->where('data_date', '<', $cutoff->addDay()->toDateString())
                ->delete();

            foreach (array_chunk($eventDatabaseRows, 500) as $chunk) {
                SeoGa4OrganicKeyEventDailyMetric::query()->insert($chunk);
            }
        });

        return [
            'period_start' => $start,
            'period_end' => $cutoff,
            'cutoff' => $cutoff,
            'stats' => [
                'property_id' => $propertyId,
                'timezone' => $timezone,
                'web_stream_count' => $webStreamCount,
                'configured_key_event_count' => count($keyEvents),
                'daily_rows' => count($dailyRows),
                'event_rows' => count($eventDatabaseRows),
                'range_days' => $days,
                'data_api_report_calls' => $reportCalls,
            ],
        ];
    }

    public function reportingLagDays(): int
    {
        $configured = config('seo_analytics.ga4_reporting_lag_days', 3);
        if ((! is_int($configured) && ! (is_string($configured) && ctype_digit($configured)))) {
            throw new RuntimeException('SEO_GA4_REPORTING_LAG_DAYS debe ser un entero entre 2 y 7.');
        }

        $lag = (int) $configured;
        if ($lag < 2 || $lag > 7) {
            throw new RuntimeException('SEO_GA4_REPORTING_LAG_DAYS debe estar entre 2 y 7.');
        }

        return $lag;
    }

    /** @return array<string, mixed> */
    private function dailyPayload(CarbonImmutable $start, CarbonImmutable $end, bool $spain = false): array
    {
        return $this->reportPayload($start, $end, ['date'], $spain);
    }

    /** @return array<string, mixed> */
    private function eventPayload(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->reportPayload($start, $end, ['date', 'eventName'], true);
    }

    /** @param array<int, string> $dimensions
     * @return array<string, mixed>
     */
    private function reportPayload(
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $dimensions,
        bool $spain,
    ): array {
        $expressions = [
            $this->exactFilter('defaultChannelGroup', 'Organic Search'),
            $this->exactFilter('platform', 'web'),
        ];
        if ($spain) {
            $expressions[] = $this->exactFilter('countryId', 'ES');
        }

        return [
            'dateRanges' => [['startDate' => $start->toDateString(), 'endDate' => $end->toDateString()]],
            'dimensions' => array_map(fn (string $name): array => ['name' => $name], $dimensions),
            'metrics' => [['name' => 'keyEvents']],
            'dimensionFilter' => ['andGroup' => ['expressions' => $expressions]],
            'orderBys' => array_map(fn (string $name): array => ['dimension' => ['dimensionName' => $name]], $dimensions),
            'keepEmptyRows' => count($dimensions) === 1,
        ];
    }

    /** @return array{filter: array{fieldName: string, stringFilter: array{matchType: string, value: string, caseSensitive: false}}} */
    private function exactFilter(string $field, string $value): array
    {
        return ['filter' => [
            'fieldName' => $field,
            'stringFilter' => [
                'matchType' => 'EXACT',
                'value' => $value,
                'caseSensitive' => false,
            ],
        ]];
    }

    /** @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function reportRows(array $payload, int &$reportCalls): array
    {
        $rows = [];
        $offset = 0;

        for ($page = 1; $page <= 100; $page++) {
            $response = $this->client->runReport($payload + ['limit' => self::PAGE_SIZE, 'offset' => $offset]);
            $reportCalls++;
            $this->validateReportQuality($response);
            $pageRows = $response['rows'] ?? [];
            if (! is_array($pageRows) || ! is_numeric($response['rowCount'] ?? count($pageRows))) {
                throw new RuntimeException('Google Analytics Data API devolvio una respuesta paginada invalida.');
            }

            array_push($rows, ...$pageRows);
            $rowCount = (int) ($response['rowCount'] ?? count($pageRows));
            $offset += count($pageRows);
            if ($offset >= $rowCount) {
                return $rows;
            }
            if ($pageRows === []) {
                throw new RuntimeException('Google Analytics Data API no avanzo durante la paginacion.');
            }
        }

        throw new RuntimeException('Google Analytics Data API excedio el limite de paginacion.');
    }

    /** @param array<string, mixed> $response */
    private function validateReportQuality(array $response): void
    {
        if (data_get($response, 'metadata.subjectToThresholding') === true) {
            throw new RuntimeException('Google Analytics devolvio un informe sujeto a umbrales de datos.');
        }

        if (data_get($response, 'metadata.dataLossFromOtherRow') === true) {
            throw new RuntimeException('Google Analytics devolvio un informe con perdida de detalle por fila (other).');
        }

        $samplingMetadata = data_get($response, 'metadata.samplingMetadatas');
        if (is_array($samplingMetadata) && $samplingMetadata !== []) {
            throw new RuntimeException('Google Analytics devolvio un informe muestreado.');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function dailyDatabaseRows(
        array $rows,
        string $propertyId,
        string $scope,
        string $timezone,
        CarbonImmutable $start,
        CarbonImmutable $end,
        mixed $extractedAt,
    ): array {
        $byDate = [];
        foreach ($rows as $row) {
            $date = $this->dateValue($row, 0);
            if ($date < $start->toDateString() || $date > $end->toDateString()) {
                throw new RuntimeException('Google Analytics devolvio un agregado fuera del periodo solicitado.');
            }
            if (isset($byDate[$date])) {
                throw new RuntimeException('Google Analytics devolvio una fecha diaria duplicada.');
            }
            $byDate[$date] = $this->metricValue($row);
        }

        $databaseRows = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $databaseRows[] = [
                'property_id' => $propertyId,
                'data_date' => $date->toDateString(),
                'country_scope' => $scope,
                'key_events' => $byDate[$date->toDateString()] ?? '0.000000',
                'source_timezone' => $timezone,
                'extracted_at' => $extractedAt,
                'created_at' => $extractedAt,
                'updated_at' => $extractedAt,
            ];
        }

        return $databaseRows;
    }

    /** @return array<int, array<string, mixed>> */
    private function eventDatabaseRows(
        array $rows,
        string $propertyId,
        string $timezone,
        CarbonImmutable $start,
        CarbonImmutable $end,
        mixed $extractedAt,
    ): array {
        $databaseRows = [];
        $identities = [];
        foreach ($rows as $row) {
            $date = $this->dateValue($row, 0);
            if ($date < $start->toDateString() || $date > $end->toDateString()) {
                throw new RuntimeException('Google Analytics devolvio detalle fuera del periodo solicitado.');
            }
            $eventName = trim((string) data_get($row, 'dimensionValues.1.value', ''));
            if ($eventName === '') {
                throw new RuntimeException('Google Analytics devolvio un eventName vacio.');
            }
            $eventHash = hash('sha256', $eventName);
            $identity = $date.'|'.$eventHash;
            if (isset($identities[$identity])) {
                throw new RuntimeException('Google Analytics devolvio detalle de evento duplicado.');
            }
            $identities[$identity] = true;

            $databaseRows[] = [
                'property_id' => $propertyId,
                'data_date' => $date,
                'country_scope' => 'ESP',
                'event_name' => $eventName,
                'event_hash' => $eventHash,
                'key_events' => $this->metricValue($row),
                'source_timezone' => $timezone,
                'extracted_at' => $extractedAt,
                'created_at' => $extractedAt,
                'updated_at' => $extractedAt,
            ];
        }

        return $databaseRows;
    }

    private function dateValue(array $row, int $index): string
    {
        $value = (string) data_get($row, "dimensionValues.{$index}.value", '');
        $date = CarbonImmutable::createFromFormat('!Ymd', $value);
        if (! $date || $date->format('Ymd') !== $value) {
            throw new RuntimeException('Google Analytics devolvio una fecha invalida.');
        }

        return $date->toDateString();
    }

    private function metricValue(array $row): string
    {
        $value = (string) data_get($row, 'metricValues.0.value', '');

        return $this->metricNormalizer->normalize($value);
    }

    private function validatedTimezone(mixed $timezone): string
    {
        if (! is_string($timezone) || trim($timezone) === '') {
            throw new RuntimeException('Google Analytics no devolvio una timezone valida.');
        }

        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new RuntimeException('Google Analytics devolvio una timezone invalida.');
        }

        return $timezone;
    }
}
