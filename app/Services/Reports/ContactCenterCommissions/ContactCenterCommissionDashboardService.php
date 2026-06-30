<?php

namespace App\Services\Reports\ContactCenterCommissions;

use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ContactCenterCommissionDashboardService
{
    private const CLOSURE_DAY = 10;

    private const OPPORTUNITY_COMMISSION = 5.0;

    private const SALE_COMMISSION = 12.0;

    private const RATIO_BONUS_PER_SALE = 2.0;

    public function build(?string $month): array
    {
        [$selectedMonth, $monthWarning] = $this->resolveMonth($month);
        $periodStart = $selectedMonth->startOfMonth();
        $periodEnd = $periodStart->addMonth();
        $closureEnd = $this->closureEndExclusive($selectedMonth);
        $issues = $this->blockingIssues();
        $warnings = array_values(array_filter([$monthWarning]));

        $summaryRows = [];
        $globalIncidents = [];
        $diagnostics = [
            'appointments_count' => 0,
            'sales_count' => 0,
            'opportunity_links_count' => 0,
            'reservation_links_count' => 0,
            'show_count' => 0,
            'appointments_without_phone_count' => 0,
            'sales_without_appointment_count' => 0,
            'sales_ambiguous_count' => 0,
        ];

        if ($issues === []) {
            $monthAppointments = $this->monthAppointments($periodStart, $periodEnd)->get();
            $allCandidateAppointments = $this->candidateAppointmentsForSales($periodEnd)->get();
            $candidateOpportunities = $this->candidateOpportunitiesForAppointments($closureEnd)->get();
            $monthSales = $this->monthSales($periodStart, $periodEnd)->get();

            $diagnostics['appointments_count'] = $monthAppointments->count();
            $diagnostics['sales_count'] = $monthSales->count();

            $opportunitiesById = $candidateOpportunities->keyBy('salesforce_id');
            $opportunitiesByPhone = $this->indexOpportunitiesByPhone($candidateOpportunities);
            $appointmentsByOpportunity = $this->indexAppointmentsByConvertedOpportunity($allCandidateAppointments);
            $appointmentsByPhone = $this->indexAppointmentsByPhone($allCandidateAppointments);

            foreach ($monthAppointments as $appointment) {
                $rowKey = $this->agentKey($appointment);
                $this->ensureRow($summaryRows, $rowKey, $this->agentName($appointment));
                $summaryRows[$rowKey]['appointment_count']++;

                $appointmentPhones = $this->phonesForLead($appointment);
                $showedUp = $this->normalizedText($appointment->appointment_attended_status) === 'acudio';

                if ($showedUp) {
                    $summaryRows[$rowKey]['show_count']++;
                    $diagnostics['show_count']++;
                }

                $summaryRows[$rowKey]['details']['appointments'][] = [
                    'lead_id' => $appointment->salesforce_id,
                    'lead_name' => (string) ($appointment->name ?? ''),
                    'phone_normalized' => implode(' / ', $appointmentPhones),
                    'agent_name' => $this->agentName($appointment),
                    'capture_date' => optional($appointment->appointment_capture_date)?->toDateString(),
                    'appointment_call' => (bool) $appointment->appointment_call,
                    'appointment_store' => (bool) $appointment->appointment_store,
                    'attended_status' => (string) ($appointment->appointment_attended_status ?? ''),
                    'candidate_status' => (string) ($appointment->candidate_status_formula ?? ''),
                    'owner_name' => (string) ($appointment->owner_name ?? ''),
                    'store_commercial_name' => (string) ($appointment->store_commercial_name ?? ''),
                    'portal' => (string) ($appointment->portal_text ?? ''),
                    'delegation' => (string) ($appointment->delegacion_encargada_text ?? ''),
                    'inclusion_reason' => $this->appointmentReason($appointment),
                ];

                if ($appointmentPhones === [] && blank($appointment->converted_opportunity_id)) {
                    $diagnostics['appointments_without_phone_count']++;
                    $incident = [
                        'type' => 'Cita sin telefono',
                        'reference_id' => $appointment->salesforce_id,
                        'reference_name' => (string) ($appointment->name ?? ''),
                        'phone_normalized' => '-',
                        'event_date' => optional($appointment->appointment_capture_date)?->toDateString(),
                        'reason' => 'No hay telefono normalizado ni ConvertedOpportunityId para cruzar la cita con oportunidades o reservas.',
                    ];
                    $summaryRows[$rowKey]['details']['incidents'][] = $incident;
                    $globalIncidents[] = $incident;
                }

                $linkedOpportunities = $this->linkedOpportunitiesForAppointment(
                    $appointment,
                    $opportunitiesById,
                    $opportunitiesByPhone,
                    $closureEnd
                );

                $bestOutcome = $this->bestOutcomeForAppointment($appointment, $linkedOpportunities);

                if ($bestOutcome !== null) {
                    $summaryRows[$rowKey]['opportunity_count']++;
                    $diagnostics['opportunity_links_count']++;

                    $linkedOpportunity = $bestOutcome['opportunity'];
                    $summaryRows[$rowKey]['details']['opportunities'][] = [
                        'lead_id' => $appointment->salesforce_id,
                        'opportunity_id' => $linkedOpportunity->salesforce_id,
                        'opportunity_name' => (string) ($linkedOpportunity->name ?? ''),
                        'phone_normalized' => implode(' / ', $this->phonesForOpportunity($linkedOpportunity)),
                        'agent_name' => $this->agentName($appointment),
                        'capture_date' => optional($appointment->appointment_capture_date)?->toDateString(),
                        'opportunity_created_date' => optional($linkedOpportunity->created_date)?->toDateString(),
                        'stage_name' => (string) ($linkedOpportunity->stage_name ?? ''),
                        'owner_name' => (string) ($linkedOpportunity->owner_name ?? ''),
                        'delegation' => (string) ($linkedOpportunity->owner_delegation ?? ''),
                        'record_type_name' => (string) ($linkedOpportunity->record_type_name ?? ''),
                        'link_origin' => $bestOutcome['link_origin'],
                        'commission_amount' => self::OPPORTUNITY_COMMISSION,
                    ];

                    if ($bestOutcome['has_reservation']) {
                        $summaryRows[$rowKey]['reservation_count']++;
                        $diagnostics['reservation_links_count']++;
                        $summaryRows[$rowKey]['details']['reservations'][] = [
                            'opportunity_id' => $linkedOpportunity->salesforce_id,
                            'opportunity_name' => (string) ($linkedOpportunity->name ?? ''),
                            'reservation_date' => optional($linkedOpportunity->reservation_date)?->toDateString(),
                            'agent_name' => $this->agentName($appointment),
                            'phone_normalized' => implode(' / ', $this->phonesForOpportunity($linkedOpportunity)),
                            'vehicle_plate' => (string) ($linkedOpportunity->vehicle_plate ?? ''),
                            'account_name' => (string) ($linkedOpportunity->account_name ?? ''),
                            'stage_name' => (string) ($linkedOpportunity->stage_name ?? ''),
                            'portal' => (string) ($linkedOpportunity->portal_resolved ?? $linkedOpportunity->portal_original ?? ''),
                            'pending_contract' => (bool) $linkedOpportunity->reservation && ! (bool) $linkedOpportunity->cv_signed,
                            'observations' => $bestOutcome['link_origin'],
                        ];
                    }
                }
            }

            foreach ($monthSales as $sale) {
                $attribution = $this->bestAppointmentForSale(
                    $sale,
                    $appointmentsByOpportunity,
                    $appointmentsByPhone,
                    $selectedMonth
                );

                if ($attribution['lead'] === null) {
                    $diagnostics['sales_without_appointment_count']++;
                    $globalIncidents[] = [
                        'type' => 'Venta sin cita previa',
                        'reference_id' => $sale->salesforce_id,
                        'reference_name' => (string) ($sale->name ?? ''),
                        'phone_normalized' => implode(' / ', $this->phonesForOpportunity($sale)),
                        'event_date' => optional($sale->cv_signed_date)?->toDateString(),
                        'reason' => 'No se encontro una cita efectiva previa del Contact Center para imputar esta venta.',
                    ];

                    continue;
                }

                $lead = $attribution['lead'];
                $rowKey = $this->agentKey($lead);
                $this->ensureRow($summaryRows, $rowKey, $this->agentName($lead));
                $summaryRows[$rowKey]['sales_count']++;
                $summaryRows[$rowKey]['details']['sales'][] = [
                    'opportunity_id' => $sale->salesforce_id,
                    'opportunity_name' => (string) ($sale->name ?? ''),
                    'phone_normalized' => implode(' / ', $this->phonesForOpportunity($sale)),
                    'agent_name' => $this->agentName($lead),
                    'contract_signed_date' => optional($sale->cv_signed_date)?->toDateString(),
                    'cv_signed' => (bool) $sale->cv_signed,
                    'stage_name' => (string) ($sale->stage_name ?? ''),
                    'owner_name' => (string) ($sale->owner_name ?? ''),
                    'delegation' => (string) ($sale->owner_delegation ?? ''),
                    'vehicle_plate' => (string) ($sale->vehicle_plate ?? ''),
                    'record_type_name' => (string) ($sale->record_type_name ?? ''),
                    'month_imputed' => $selectedMonth->format('Y-m'),
                    'sale_commission_amount' => self::SALE_COMMISSION,
                    'ratio_bonus_applied' => false,
                    'observations' => $attribution['reason'],
                ];

                if ($attribution['ambiguous']) {
                    $diagnostics['sales_ambiguous_count']++;
                    $incident = [
                        'type' => 'Venta con varios captadores posibles',
                        'reference_id' => $sale->salesforce_id,
                        'reference_name' => (string) ($sale->name ?? ''),
                        'phone_normalized' => implode(' / ', $this->phonesForOpportunity($sale)),
                        'event_date' => optional($sale->cv_signed_date)?->toDateString(),
                        'reason' => 'Se imputa por cercania temporal, pero existe mas de una cita candidata valida y conviene revisar la atribucion antes del cierre.',
                    ];
                    $summaryRows[$rowKey]['details']['incidents'][] = $incident;
                    $globalIncidents[] = $incident;
                }
            }
        }

        $summaryRows = collect($summaryRows)
            ->map(function (array $row): array {
                $ratio = $this->divide($row['sales_count'], $row['appointment_count']);
                $opportunityCommission = round($row['opportunity_count'] * self::OPPORTUNITY_COMMISSION, 2);
                $salesCommission = round($row['sales_count'] * self::SALE_COMMISSION, 2);
                $ratioBonus = $ratio > 0.03
                    ? round($row['sales_count'] * self::RATIO_BONUS_PER_SALE, 2)
                    : 0.0;
                $volumeBonus = $this->volumeBonus($row['sales_count']);
                $automaticTotal = round($opportunityCommission + $salesCommission + $ratioBonus + $volumeBonus, 2);
                $showRate = $this->divide($row['show_count'], $row['appointment_count']);

                $row['sales_ratio'] = $ratio;
                $row['opportunity_commission'] = $opportunityCommission;
                $row['sales_commission'] = $salesCommission;
                $row['ratio_bonus'] = $ratioBonus;
                $row['volume_bonus'] = $volumeBonus;
                $row['show_rate'] = $showRate;
                $row['show_commission'] = 0.0;
                $row['automatic_total'] = $automaticTotal;
                $row['manual_adjustment'] = 0.0;
                $row['final_total'] = $automaticTotal;
                $row['review_status'] = count($row['details']['incidents']) > 0 ? 'Revisar' : 'OK';
                $row['observations'] = count($row['details']['incidents']) > 0
                    ? count($row['details']['incidents']).' incidencias'
                    : 'Sin incidencias';

                if ($ratioBonus > 0) {
                    foreach ($row['details']['sales'] as &$detail) {
                        $detail['ratio_bonus_applied'] = true;
                    }
                    unset($detail);
                }

                foreach (['appointments', 'opportunities', 'reservations', 'sales', 'incidents'] as $detailKey) {
                    $row['details'][$detailKey] = collect($row['details'][$detailKey])
                        ->sortBy([
                            ['event_date', 'desc'],
                            ['capture_date', 'desc'],
                            ['contract_signed_date', 'desc'],
                            ['reservation_date', 'desc'],
                            ['opportunity_created_date', 'desc'],
                            ['reference_name', 'asc'],
                            ['opportunity_name', 'asc'],
                            ['lead_name', 'asc'],
                        ])
                        ->values()
                        ->all();
                }

                return $row;
            })
            ->sortByDesc('final_total')
            ->values()
            ->all();

        if ($issues === [] && $diagnostics['appointments_count'] === 0) {
            $warnings[] = 'No hay citas efectivas del Contact Center para el mes seleccionado. Re-sincroniza leads con salesforce:sync-monthly-commercial si esperabas actividad.';
        }

        if ($issues === [] && $diagnostics['sales_without_appointment_count'] > 0) {
            $warnings[] = 'Hay '.$diagnostics['sales_without_appointment_count'].' ventas del mes sin una cita previa imputable al Contact Center. Quedan en incidencias para revision.';
        }

        if ($issues === [] && $diagnostics['sales_ambiguous_count'] > 0) {
            $warnings[] = 'Hay '.$diagnostics['sales_ambiguous_count'].' ventas con varios captadores posibles. El sistema las imputa por cercania temporal, pero conviene revisarlas.';
        }

        return [
            'ready' => $issues === [],
            'month' => $selectedMonth->format('Y-m'),
            'month_label' => $selectedMonth->translatedFormat('F Y'),
            'closure_cutoff_date' => $closureEnd->subDay()->toDateString(),
            'issues' => $issues,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'diagnostics' => $diagnostics,
            'summary_rows' => $summaryRows,
            'global_incidents' => collect($globalIncidents)
                ->sortByDesc(fn (array $row) => $row['event_date'] ?? '')
                ->values()
                ->all(),
        ];
    }

    private function monthAppointments(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return SalesforceLead::query()
            ->whereNotNull('appointment_capture_date')
            ->whereDate('appointment_capture_date', '>=', $periodStart->toDateString())
            ->whereDate('appointment_capture_date', '<', $periodEnd->toDateString())
            ->where(function (Builder $query): void {
                $query->where('appointment_call', true)
                    ->orWhere('appointment_store', true);
            })
            ->where(function (Builder $query): void {
                $query->whereNotNull('appointment_setter_id')
                    ->orWhereNotNull('appointment_setter_name');
            });
    }

    private function candidateAppointmentsForSales(CarbonImmutable $periodEnd): Builder
    {
        return SalesforceLead::query()
            ->whereNotNull('appointment_capture_date')
            ->whereDate('appointment_capture_date', '<', $periodEnd->toDateString())
            ->where(function (Builder $query): void {
                $query->where('appointment_call', true)
                    ->orWhere('appointment_store', true);
            })
            ->where(function (Builder $query): void {
                $query->whereNotNull('appointment_setter_id')
                    ->orWhereNotNull('appointment_setter_name');
            });
    }

    private function candidateOpportunitiesForAppointments(CarbonImmutable $closureEnd): Builder
    {
        return SalesforceOpportunity::query()
            ->where(function (Builder $query) use ($closureEnd): void {
                $date = $closureEnd->toDateString();

                $query
                    ->where(function (Builder $dateQuery) use ($date): void {
                        $dateQuery->whereNotNull('created_date')
                            ->whereDate('created_date', '<', $date);
                    })
                    ->orWhere(function (Builder $dateQuery) use ($date): void {
                        $dateQuery->where('reservation', true)
                            ->whereNotNull('reservation_date')
                            ->whereDate('reservation_date', '<', $date);
                    })
                    ->orWhere(function (Builder $dateQuery) use ($date): void {
                        $dateQuery->where('cv_signed', true)
                            ->whereNotNull('cv_signed_date')
                            ->whereDate('cv_signed_date', '<', $date);
                    });
            });
    }

    private function monthSales(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return SalesforceOpportunity::query()
            ->where('cv_signed', true)
            ->whereNotNull('cv_signed_date')
            ->whereDate('cv_signed_date', '>=', $periodStart->toDateString())
            ->whereDate('cv_signed_date', '<', $periodEnd->toDateString())
            ->whereRaw('LOWER(COALESCE(stage_name, \'\')) <> ?', ['cerrada perdida']);
    }

    private function linkedOpportunitiesForAppointment(
        SalesforceLead $appointment,
        Collection $opportunitiesById,
        array $opportunitiesByPhone,
        CarbonImmutable $closureEnd
    ): Collection {
        $records = collect();

        if (filled($appointment->converted_opportunity_id)) {
            $record = $opportunitiesById->get($appointment->converted_opportunity_id);

            if ($record !== null) {
                $records->push($record);
            }
        }

        foreach ($this->phonesForLead($appointment) as $phone) {
            foreach ($opportunitiesByPhone[$phone] ?? [] as $record) {
                $records->push($record);
            }
        }

        $captureDate = optional($appointment->appointment_capture_date)?->toDateString();

        return $records
            ->filter(function (SalesforceOpportunity $opportunity) use ($captureDate, $closureEnd): bool {
                if (! $this->opportunityHasOutcomeAfterDate($opportunity, $captureDate)) {
                    return false;
                }

                return $this->opportunityRelevantBeforeClosure($opportunity, $closureEnd);
            })
            ->unique('salesforce_id')
            ->values();
    }

    private function bestOutcomeForAppointment(SalesforceLead $appointment, Collection $linkedOpportunities): ?array
    {
        if ($linkedOpportunities->isEmpty()) {
            return null;
        }

        $convertedOpportunityId = (string) ($appointment->converted_opportunity_id ?? '');
        $captureDate = optional($appointment->appointment_capture_date)?->toDateString();

        return $linkedOpportunities
            ->map(function (SalesforceOpportunity $opportunity) use ($convertedOpportunityId, $captureDate): array {
                $createdDate = optional($opportunity->created_date)?->toDateString();
                $reservationDate = optional($opportunity->reservation_date)?->toDateString();
                $saleDate = optional($opportunity->cv_signed_date)?->toDateString();
                $firstEventDate = collect([$createdDate, $reservationDate, $saleDate])
                    ->filter()
                    ->sort()
                    ->first();

                return [
                    'opportunity' => $opportunity,
                    'has_reservation' => (bool) $opportunity->reservation,
                    'link_origin' => $this->bestOutcomeLabel($opportunity, $captureDate),
                    'score' => [
                        'direct_match' => $convertedOpportunityId !== '' && $opportunity->salesforce_id === $convertedOpportunityId ? 1 : 0,
                        'reservation' => (bool) $opportunity->reservation ? 1 : 0,
                        'sale' => (bool) $opportunity->cv_signed ? 1 : 0,
                        'event_date' => $firstEventDate ?? '9999-12-31',
                    ],
                ];
            })
            ->sort(function (array $a, array $b): int {
                return [$b['score']['direct_match'], $b['score']['reservation'], $b['score']['sale'], $a['score']['event_date']]
                    <=> [$a['score']['direct_match'], $a['score']['reservation'], $a['score']['sale'], $b['score']['event_date']];
            })
            ->first();
    }

    private function bestAppointmentForSale(
        SalesforceOpportunity $sale,
        array $appointmentsByOpportunity,
        array $appointmentsByPhone,
        CarbonImmutable $selectedMonth
    ): array {
        $candidates = collect();

        foreach ($appointmentsByOpportunity[$sale->salesforce_id] ?? [] as $lead) {
            $candidates->push($lead);
        }

        foreach ($this->phonesForOpportunity($sale) as $phone) {
            foreach ($appointmentsByPhone[$phone] ?? [] as $lead) {
                $candidates->push($lead);
            }
        }

        $saleDate = optional($sale->cv_signed_date)?->toDateString();

        $ranked = $candidates
            ->unique('salesforce_id')
            ->filter(fn (SalesforceLead $lead): bool => optional($lead->appointment_capture_date)?->toDateString() !== null)
            ->filter(fn (SalesforceLead $lead): bool => optional($lead->appointment_capture_date)?->toDateString() <= $saleDate)
            ->map(function (SalesforceLead $lead) use ($sale, $selectedMonth): array {
                $captureDate = optional($lead->appointment_capture_date)?->toDateString();
                $directMatch = filled($lead->converted_opportunity_id) && $lead->converted_opportunity_id === $sale->salesforce_id;
                $sameMonth = optional($lead->appointment_capture_date)?->startOfMonth()?->equalTo($selectedMonth) ?? false;

                return [
                    'lead' => $lead,
                    'reason' => $directMatch
                        ? 'Atribucion por ConvertedOpportunityId'
                        : ($sameMonth ? 'Atribucion por telefono y cita del mismo mes' : 'Atribucion por telefono y cita previa'),
                    'score' => [
                        'same_month' => $sameMonth ? 1 : 0,
                        'direct_match' => $directMatch ? 1 : 0,
                        'capture_date' => $captureDate ?? '0000-00-00',
                    ],
                ];
            })
            ->sort(function (array $a, array $b): int {
                return [$b['score']['same_month'], $b['score']['direct_match'], $b['score']['capture_date']]
                    <=> [$a['score']['same_month'], $a['score']['direct_match'], $a['score']['capture_date']];
            })
            ->values();

        if ($ranked->isEmpty()) {
            return [
                'lead' => null,
                'reason' => 'Sin cita previa imputable',
                'ambiguous' => false,
            ];
        }

        $top = $ranked->first();
        $second = $ranked->get(1);
        $ambiguous = $second !== null
            && $top['score'] === $second['score']
            && $this->agentKey($top['lead']) !== $this->agentKey($second['lead']);

        return [
            'lead' => $top['lead'],
            'reason' => $top['reason'],
            'ambiguous' => $ambiguous,
        ];
    }

    private function ensureRow(array &$rows, string $agentKey, string $agentName): void
    {
        if (isset($rows[$agentKey])) {
            return;
        }

        $rows[$agentKey] = [
            'agent_key' => $agentKey,
            'agent_name' => $agentName,
            'appointment_count' => 0,
            'opportunity_count' => 0,
            'reservation_count' => 0,
            'sales_count' => 0,
            'show_count' => 0,
            'details' => [
                'appointments' => [],
                'opportunities' => [],
                'reservations' => [],
                'sales' => [],
                'incidents' => [],
            ],
        ];
    }

    private function indexOpportunitiesByPhone(Collection $opportunities): array
    {
        $index = [];

        foreach ($opportunities as $opportunity) {
            foreach ($this->phonesForOpportunity($opportunity) as $phone) {
                $index[$phone] ??= [];
                $index[$phone][] = $opportunity;
            }
        }

        return $index;
    }

    private function indexAppointmentsByConvertedOpportunity(Collection $appointments): array
    {
        $index = [];

        foreach ($appointments as $appointment) {
            if (blank($appointment->converted_opportunity_id)) {
                continue;
            }

            $index[$appointment->converted_opportunity_id] ??= [];
            $index[$appointment->converted_opportunity_id][] = $appointment;
        }

        return $index;
    }

    private function indexAppointmentsByPhone(Collection $appointments): array
    {
        $index = [];

        foreach ($appointments as $appointment) {
            foreach ($this->phonesForLead($appointment) as $phone) {
                $index[$phone] ??= [];
                $index[$phone][] = $appointment;
            }
        }

        return $index;
    }

    private function phonesForLead(SalesforceLead $lead): array
    {
        return collect([
            $lead->phone,
            $lead->mobile_phone,
            data_get($lead->raw_payload, 'Phone'),
            data_get($lead->raw_payload, 'MobilePhone'),
        ])->map(fn (mixed $value) => $this->normalizePhone($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function phonesForOpportunity(SalesforceOpportunity $opportunity): array
    {
        return collect([
            $opportunity->account_phone,
            data_get($opportunity->raw_payload, 'Account.Phone'),
            data_get($opportunity->raw_payload, 'Phone'),
        ])->map(fn (mixed $value) => $this->normalizePhone($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function opportunityHasOutcomeAfterDate(SalesforceOpportunity $opportunity, ?string $captureDate): bool
    {
        if ($captureDate === null) {
            return false;
        }

        return collect([
            optional($opportunity->created_date)?->toDateString(),
            (bool) $opportunity->reservation ? optional($opportunity->reservation_date)?->toDateString() : null,
            (bool) $opportunity->cv_signed ? optional($opportunity->cv_signed_date)?->toDateString() : null,
        ])->filter()
            ->contains(fn (string $date): bool => $date >= $captureDate);
    }

    private function opportunityRelevantBeforeClosure(SalesforceOpportunity $opportunity, CarbonImmutable $closureEnd): bool
    {
        $cutoffDate = $closureEnd->toDateString();

        return collect([
            optional($opportunity->created_date)?->toDateString(),
            optional($opportunity->reservation_date)?->toDateString(),
            optional($opportunity->cv_signed_date)?->toDateString(),
        ])->filter()
            ->contains(fn (string $date): bool => $date < $cutoffDate);
    }

    private function bestOutcomeLabel(SalesforceOpportunity $opportunity, ?string $captureDate): string
    {
        $reservationDate = optional($opportunity->reservation_date)?->toDateString();
        $saleDate = optional($opportunity->cv_signed_date)?->toDateString();
        $createdDate = optional($opportunity->created_date)?->toDateString();

        if ($reservationDate !== null && $reservationDate >= (string) $captureDate) {
            return 'Reserva posterior a la cita';
        }

        if ($saleDate !== null && $saleDate >= (string) $captureDate) {
            return 'Contrato firmado posterior a la cita';
        }

        return 'Oportunidad creada posterior a la cita';
    }

    private function appointmentReason(SalesforceLead $lead): string
    {
        return match (true) {
            (bool) $lead->appointment_call && (bool) $lead->appointment_store => 'Cita llamada y cita tienda',
            (bool) $lead->appointment_store => 'Cita tienda',
            default => 'Cita llamada',
        };
    }

    private function agentKey(SalesforceLead $lead): string
    {
        $raw = $lead->appointment_setter_id ?: $lead->appointment_setter_name;

        return $this->normalizedText($raw) ?: 'sin-agente';
    }

    private function agentName(SalesforceLead $lead): string
    {
        return $this->displayText($lead->appointment_setter_name ?: $lead->appointment_setter_id ?: 'Sin agente');
    }

    private function normalizedText(?string $value): string
    {
        return trim(Str::lower(Str::ascii(preg_replace('/\s+/', ' ', (string) $value))));
    }

    private function displayText(?string $value): string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $value));

        return $text !== '' ? $text : 'Sin dato';
    }

    private function normalizePhone(mixed $value): ?string
    {
        $value = preg_replace('/\D+/', '', (string) $value);
        $value = preg_replace('/^34(?=\d{9}$)/', '', $value ?? '');

        return $value !== '' ? $value : null;
    }

    private function divide(int|float $numerator, int|float $denominator): float
    {
        if ((float) $denominator === 0.0) {
            return 0.0;
        }

        return round((float) $numerator / (float) $denominator, 6);
    }

    private function volumeBonus(int $salesCount): float
    {
        return match (true) {
            $salesCount >= 20 => 500.0,
            $salesCount >= 15 => 250.0,
            $salesCount >= 10 => 100.0,
            default => 0.0,
        };
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
            return [$lastClosedMonth, 'Solo se permiten meses cerrados. Se ha cargado automaticamente el ultimo mes cerrado disponible.'];
        }

        return [$selectedMonth, null];
    }

    private function closureEndExclusive(CarbonImmutable $selectedMonth): CarbonImmutable
    {
        return $selectedMonth->addMonth()->setDay(self::CLOSURE_DAY)->addDay()->startOfDay();
    }

    private function blockingIssues(): array
    {
        $issues = [];

        if (! Schema::hasTable('salesforce_leads')) {
            $issues[] = 'La tabla local salesforce_leads no existe todavia.';
        }

        if (! Schema::hasTable('salesforce_opportunities')) {
            $issues[] = 'La tabla local salesforce_opportunities no existe todavia.';
        }

        foreach ([
            'salesforce_id',
            'name',
            'appointment_setter_name',
            'appointment_capture_date',
            'appointment_call',
            'appointment_store',
            'appointment_attended_status',
            'phone',
            'mobile_phone',
            'converted_opportunity_id',
            'portal_text',
        ] as $column) {
            if (Schema::hasTable('salesforce_leads') && ! Schema::hasColumn('salesforce_leads', $column)) {
                $issues[] = "Falta la columna local salesforce_leads.{$column}. Ejecuta migrate y re-sincroniza leads.";
            }
        }

        foreach ([
            'salesforce_id',
            'name',
            'created_date',
            'stage_name',
            'record_type_name',
            'owner_name',
            'owner_delegation',
            'account_phone',
            'account_name',
            'vehicle_plate',
            'portal_resolved',
            'portal_original',
            'reservation',
            'reservation_date',
            'cv_signed',
            'cv_signed_date',
            'raw_payload',
        ] as $column) {
            if (Schema::hasTable('salesforce_opportunities') && ! Schema::hasColumn('salesforce_opportunities', $column)) {
                $issues[] = "Falta la columna local salesforce_opportunities.{$column}.";
            }
        }

        return $issues;
    }
}
