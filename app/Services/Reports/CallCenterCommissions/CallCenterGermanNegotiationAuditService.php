<?php

namespace App\Services\Reports\CallCenterCommissions;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceTasacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CallCenterGermanNegotiationAuditService
{
    private const REASON_INCLUDED = 'included';
    private const REASON_NOT_GERMAN = 'not_german';
    private const REASON_NEGOTIATION_EMPTY = 'negotiation_1_empty';
    private const REASON_MISSING_OPPORTUNITY = 'missing_related_opportunity';
    private const REASON_MISSING_CONTRACT_DATE = 'missing_contract_signed_date';
    private const REASON_CV_NOT_SIGNED = 'cv_not_signed';
    private const REASON_CONTRACT_BEFORE_RANGE = 'contract_before_range';
    private const REASON_CONTRACT_AFTER_RANGE = 'contract_after_range';

    private const REASON_LABELS = [
        self::REASON_INCLUDED => 'Entra en comisión',
        self::REASON_NOT_GERMAN => 'Seguimiento distinto de German',
        self::REASON_NEGOTIATION_EMPTY => 'Negociación 1 vacía',
        self::REASON_MISSING_OPPORTUNITY => 'Sin oportunidad relacionada',
        self::REASON_MISSING_CONTRACT_DATE => 'Sin fecha firma contrato',
        self::REASON_CV_NOT_SIGNED => 'Contrato CV firmado = false/vacío',
        self::REASON_CONTRACT_BEFORE_RANGE => 'Fecha firma contrato antes del rango',
        self::REASON_CONTRACT_AFTER_RANGE => 'Fecha firma contrato después del rango',
    ];

    private array $opportunityCache = [];

    public function audit(?string $month, ?string $from = null, ?string $to = null, int $exampleLimit = 5): array
    {
        [$selectedMonth] = $this->resolveMonth($month);
        [$periodStart, $periodEndExclusive] = $this->resolveContractDateRange($selectedMonth, $from, $to);

        $summary = [
            'month' => $selectedMonth->format('Y-m'),
            'contract_from' => $periodStart->toDateString(),
            'contract_to' => $periodEndExclusive->subDay()->toDateString(),
            'tasaciones_total' => 0,
            'german_total' => 0,
            'included_total' => 0,
            'excluded_total' => 0,
            'reasons' => [],
        ];

        foreach (array_keys(self::REASON_LABELS) as $reasonKey) {
            $summary['reasons'][$reasonKey] = [
                'label' => self::REASON_LABELS[$reasonKey],
                'total' => 0,
                'examples' => [],
            ];
        }

        SalesforceTasacion::query()
            ->orderBy('id')
            ->chunkById(1000, function (Collection $tasaciones) use (&$summary, $periodStart, $periodEndExclusive, $exampleLimit): void {
                foreach ($tasaciones as $tasacion) {
                    $summary['tasaciones_total']++;

                    $reason = $this->classifyTasacion($tasacion, $periodStart, $periodEndExclusive);

                    $summary['reasons'][$reason]['total']++;

                    if (count($summary['reasons'][$reason]['examples']) < $exampleLimit) {
                        $summary['reasons'][$reason]['examples'][] = $this->exampleRow($tasacion);
                    }

                    if ($reason !== self::REASON_NOT_GERMAN) {
                        $summary['german_total']++;
                    }

                    if ($reason === self::REASON_INCLUDED) {
                        $summary['included_total']++;
                    } elseif ($reason !== self::REASON_NOT_GERMAN) {
                        $summary['excluded_total']++;
                    }
                }
            });

        return $summary;
    }

    private function classifyTasacion(
        SalesforceTasacion $tasacion,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEndExclusive
    ): string {
        if (! $this->isGermanName($this->trackingName($tasacion))) {
            return self::REASON_NOT_GERMAN;
        }

        if (! filled($this->negotiationValue($tasacion, 1))) {
            return self::REASON_NEGOTIATION_EMPTY;
        }

        $opportunity = $this->linkedOpportunity($tasacion);

        if ($opportunity === null) {
            return self::REASON_MISSING_OPPORTUNITY;
        }

        $contractDate = $this->contractSignedDate($tasacion, $opportunity);

        if ($contractDate === null) {
            return self::REASON_MISSING_CONTRACT_DATE;
        }

        $cvSigned = $this->cvSigned($tasacion, $opportunity);

        if (! $cvSigned) {
            return self::REASON_CV_NOT_SIGNED;
        }

        if ($contractDate->lessThan($periodStart)) {
            return self::REASON_CONTRACT_BEFORE_RANGE;
        }

        if ($contractDate->greaterThanOrEqualTo($periodEndExclusive)) {
            return self::REASON_CONTRACT_AFTER_RANGE;
        }

        return self::REASON_INCLUDED;
    }

    private function exampleRow(SalesforceTasacion $tasacion): array
    {
        $opportunity = $this->linkedOpportunity($tasacion);
        $contractDate = $this->contractSignedDate($tasacion, $opportunity);

        return [
            'tasacion_id' => $tasacion->salesforce_id,
            'tasacion_name' => (string) ($tasacion->name ?? ''),
            'opportunity_id' => $this->opportunitySalesforceId($tasacion),
            'opportunity_name' => (string) ($tasacion->opportunity_name ?? $this->payloadValue($tasacion, 'Oportunidad__r.Name') ?? ''),
            'tracking_name' => (string) ($this->trackingName($tasacion) ?? ''),
            'negotiation_1' => (string) ($this->negotiationValue($tasacion, 1) ?? ''),
            'contract_signed_date' => $contractDate?->toDateString(),
            'cv_signed' => $this->cvSigned($tasacion, $opportunity) ? 'true' : 'false',
        ];
    }

    private function opportunitySalesforceId(SalesforceTasacion $tasacion): ?string
    {
        $value = $tasacion->opportunity_salesforce_id
            ?: $this->payloadValue($tasacion, 'Oportunidad__c')
            ?: $this->payloadValue($tasacion, 'Opportunity__c')
            ?: $this->payloadValue($tasacion, 'RES_BUS_Oportunidad__c')
            ?: $this->payloadValue($tasacion, 'TAS_BUS_Oportunidad__c');

        return filled($value) ? (string) $value : null;
    }

    private function linkedOpportunity(SalesforceTasacion $tasacion): ?SalesforceOpportunity
    {
        $salesforceId = $this->opportunitySalesforceId($tasacion);

        if (! filled($salesforceId)) {
            return null;
        }

        if (array_key_exists($salesforceId, $this->opportunityCache)) {
            return $this->opportunityCache[$salesforceId];
        }

        $this->opportunityCache[$salesforceId] = SalesforceOpportunity::query()
            ->where('salesforce_id', $salesforceId)
            ->first();

        return $this->opportunityCache[$salesforceId];
    }

    private function trackingName(SalesforceTasacion $tasacion): ?string
    {
        return $this->payloadValue($tasacion, 'Seguimiento__c')
            ?? $tasacion->tracking_name;
    }

    private function negotiationValue(SalesforceTasacion $tasacion, int $index): mixed
    {
        $field = 'negotiation_'.$index;

        return $this->payloadValue($tasacion, 'Negociaci_n_'.$index.'__c')
            ?? $this->payloadValue($tasacion, 'Negociacion_'.$index.'__c')
            ?? $tasacion->{$field};
    }

    private function contractSignedDate(SalesforceTasacion $tasacion, ?SalesforceOpportunity $opportunity): ?CarbonImmutable
    {
        $rawDate = $this->payloadValue($tasacion, 'Oportunidad__r.Fecha_firma_contrato__c')
            ?? $this->payloadValue($tasacion, 'Opportunity__r.Fecha_firma_contrato__c')
            ?? $this->payloadValue($tasacion, 'RES_BUS_Oportunidad__r.Fecha_firma_contrato__c')
            ?? $this->payloadValue($tasacion, 'TAS_BUS_Oportunidad__r.Fecha_firma_contrato__c')
            ?? optional($tasacion->contract_signed_date)?->toDateString()
            ?? optional($opportunity?->cv_signed_date)?->toDateString();

        if (! filled($rawDate)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $rawDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function cvSigned(SalesforceTasacion $tasacion, ?SalesforceOpportunity $opportunity): bool
    {
        $rawValue = $this->payloadValue($tasacion, 'Oportunidad__r.OPO_CAS_Contrato_CV_firmado__c')
            ?? $this->payloadValue($tasacion, 'Opportunity__r.OPO_CAS_Contrato_CV_firmado__c')
            ?? $this->payloadValue($tasacion, 'RES_BUS_Oportunidad__r.OPO_CAS_Contrato_CV_firmado__c')
            ?? $this->payloadValue($tasacion, 'TAS_BUS_Oportunidad__r.OPO_CAS_Contrato_CV_firmado__c');

        if ($rawValue !== null && $rawValue !== '') {
            return (bool) $rawValue;
        }

        if ($opportunity !== null) {
            return (bool) $opportunity->cv_signed;
        }

        return false;
    }

    private function payloadValue(SalesforceTasacion $tasacion, string $path): mixed
    {
        $payload = is_array($tasacion->raw_payload) ? $tasacion->raw_payload : [];

        if ($payload === []) {
            return null;
        }

        $segments = explode('.', $path);
        $current = $payload;

        foreach ($segments as $segment) {
            if (! is_array($current)) {
                return null;
            }

            $matchedKey = null;

            foreach ($current as $key => $value) {
                if (Str::lower((string) $key) === Str::lower($segment)) {
                    $matchedKey = $key;
                    break;
                }
            }

            if ($matchedKey === null) {
                return null;
            }

            $current = $current[$matchedKey];
        }

        return $current;
    }

    private function isGermanName(?string $value): bool
    {
        $normalized = $this->normalizeText($value);

        return $normalized !== '' && str_contains($normalized, 'german');
    }

    private function normalizeText(?string $value): string
    {
        $ascii = Str::ascii((string) $value);

        return trim(Str::lower(preg_replace('/\s+/', ' ', $ascii)));
    }

    private function resolveMonth(?string $month): array
    {
        $lastClosedMonth = CarbonImmutable::now()->startOfMonth()->subMonth();

        if (! preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            return [$lastClosedMonth, null];
        }

        try {
            $selectedMonth = CarbonImmutable::createFromFormat('Y-m', (string) $month)->startOfMonth();
        } catch (\Throwable) {
            return [$lastClosedMonth, null];
        }

        if ($selectedMonth->greaterThanOrEqualTo(CarbonImmutable::now()->startOfMonth())) {
            return [$lastClosedMonth, 'Solo se permiten meses cerrados.'];
        }

        return [$selectedMonth, null];
    }

    private function resolveContractDateRange(
        CarbonImmutable $selectedMonth,
        ?string $contractFrom,
        ?string $contractTo
    ): array {
        $monthStart = $selectedMonth->startOfMonth();
        $monthEndExclusive = $monthStart->addMonth();

        try {
            $resolvedStart = filled($contractFrom)
                ? CarbonImmutable::parse($contractFrom)->startOfDay()
                : $monthStart;
            $resolvedEndExclusive = filled($contractTo)
                ? CarbonImmutable::parse($contractTo)->addDay()->startOfDay()
                : $monthEndExclusive;
        } catch (\Throwable) {
            return [$monthStart, $monthEndExclusive, null];
        }

        if ($resolvedEndExclusive->lessThanOrEqualTo($resolvedStart)) {
            return [$monthStart, $monthEndExclusive, null];
        }

        $clampedStart = $resolvedStart->lessThan($monthStart) ? $monthStart : $resolvedStart;
        $clampedEndExclusive = $resolvedEndExclusive->greaterThan($monthEndExclusive) ? $monthEndExclusive : $resolvedEndExclusive;

        if ($clampedEndExclusive->lessThanOrEqualTo($clampedStart)) {
            return [$monthStart, $monthEndExclusive, null];
        }

        return [$clampedStart, $clampedEndExclusive, null];
    }
}
