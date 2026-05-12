<?php

namespace App\Services\Reports\Leads;

use App\Models\MasterCallDelegationMapping;
use App\Models\MasterDelegation;
use App\Models\MasterFormSenderMapping;
use App\Models\MasterPortal;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LeadNormalizationService
{
    public function normalize(array $lead): array
    {
        $channel = $this->resolveChannel($lead);
        $portalOriginal = $this->resolvePortalOriginal($lead, $channel);
        $portalGroup = $this->resolvePortalGroup($portalOriginal);
        $isExposition = $this->isExposition($portalOriginal);
        $delegation = $this->resolveDelegation($lead, $channel, $portalOriginal, $isExposition);
        $quality = $this->resolveDataQuality($lead, $channel, $portalOriginal, $portalGroup, $delegation, $isExposition);

        return [
            'channel_direction' => $channel,
            'portal_original' => $portalOriginal,
            'portal_group' => $portalGroup,
            'delegation_name' => $delegation['delegation_name'],
            'commercial_group' => $delegation['commercial_group'],
            'commercial_name' => Arr::get($lead, 'worked_by_name') ?: Arr::get($lead, 'owner_name'),
            'is_exposition' => $isExposition,
            'is_converted' => $this->isConverted($lead),
            'is_discarded' => $this->isDiscarded($lead),
            'is_potential' => $this->isPotential($lead),
            'has_task_event' => $this->hasTaskEvent($lead),
            'has_recent_follow_up' => $this->hasRecentFollowUp($lead),
            'minutes_to_assignment' => $this->minutesBetween(
                Arr::get($lead, 'lead_created_at'),
                Arr::get($lead, 'assigned_at')
            ),
            'minutes_to_first_task_event' => $this->minutesBetween(
                Arr::get($lead, 'lead_created_at'),
                Arr::get($lead, 'first_task_event_at')
            ),
            'data_quality_status' => $quality['status'],
            'data_quality_issue' => $quality['issue'],
        ];
    }

    public function resolveChannel(array $lead): string
    {
        $medioNuevo = $this->normalizeComparable((string) Arr::get($lead, 'medio_nuevo'));

        return $medioNuevo === $this->normalizeComparable('Llamada')
            ? 'Llamada'
            : 'Formulario';
    }

    public function resolvePortalOriginal(array $lead, string $channel): string
    {
        if ($channel === 'Llamada') {
            return $this->cleanText(Arr::get($lead, 'fuente_nuevo')) ?: 'Sin portal';
        }

        return $this->cleanText(Arr::get($lead, 'portal'))
            ?: $this->cleanText(Arr::get($lead, 'lea_sel_fuente_origen'))
            ?: $this->cleanText(Arr::get($lead, 'fuente_nuevo'))
            ?: 'Sin portal';
    }

    public function resolvePortalGroup(string $portalOriginal): ?string
    {
        $portal = MasterPortal::query()
            ->where('portal_original', $portalOriginal)
            ->where('is_active', true)
            ->first();

        return $portal?->portal_group;
    }

    public function resolveDelegation(array $lead, string $channel, string $portalOriginal, bool $isExposition): array
    {
        if ($channel === 'Llamada') {
            return $this->resolveCallDelegation($lead, $portalOriginal);
        }

        return $this->resolveFormDelegation($lead, $portalOriginal, $isExposition);
    }

    private function resolveCallDelegation(array $lead, string $portalOriginal): array
    {
        $receivedValue = $this->cleanText(Arr::get($lead, 'delegacion_encargada_text'));

        if ($receivedValue === null) {
            return $this->emptyDelegation();
        }

        $mapping = MasterCallDelegationMapping::query()
            ->where('portal_original', $portalOriginal)
            ->where('received_value', $receivedValue)
            ->where('status', 'active')
            ->first();

        if ($mapping === null) {
            return [
                'type' => null,
                'delegation_name' => null,
                'commercial_group' => null,
                'mapping_found' => false,
            ];
        }

        return [
            'type' => $mapping->type,
            'delegation_name' => $mapping->delegation_name,
            'commercial_group' => $mapping->commercial_group,
            'mapping_found' => true,
        ];
    }

    private function resolveFormDelegation(array $lead, string $portalOriginal, bool $isExposition): array
    {
        $sender = $this->cleanEmail(Arr::get($lead, 'remitente_lead'));

        if ($sender !== null) {
            $mapping = MasterFormSenderMapping::query()
                ->where('portal_original', $portalOriginal)
                ->where('sender_email', $sender)
                ->where('status', 'active')
                ->when($this->cleanText(Arr::get($lead, 'portal_value')), function ($query, string $portalValue) {
                    $query->where(function ($subQuery) use ($portalValue) {
                        $subQuery
                            ->where('portal_value', $portalValue)
                            ->orWhereNull('portal_value');
                    });
                })
                ->orderByRaw('portal_value IS NULL')
                ->first();

            if ($mapping !== null) {
                return [
                    'type' => $mapping->type,
                    'delegation_name' => $mapping->delegation_name,
                    'commercial_group' => $mapping->commercial_group,
                    'mapping_found' => true,
                    'fallback_used' => false,
                ];
            }

            /*
            * Si el remitente no está mapeado, intentamos rescatar delegación por campos fallback.
            * Esto es importante para casos como desarrollo@hrmotor.com o noreply@mails.sumauto.com,
            * donde el remitente puede ser genérico pero la delegación viene en Delegacion_Encargada_Bueno__c.
            */
            $fallback = $this->resolveFallbackDelegation($lead, $isExposition);

            return [
                'type' => $fallback['type'],
                'delegation_name' => $fallback['delegation_name'],
                'commercial_group' => $fallback['commercial_group'],
                'mapping_found' => false,
                'fallback_used' => $fallback['delegation_name'] !== null || $fallback['commercial_group'] !== null,
            ];
        }

        $fallback = $this->resolveFallbackDelegation($lead, $isExposition);

        return array_merge($fallback, [
            'fallback_used' => $fallback['delegation_name'] !== null || $fallback['commercial_group'] !== null,
        ]);
    }

    private function resolveFallbackDelegation(array $lead, bool $isExposition): array
    {
        $fallbackValue = $this->cleanText(Arr::get($lead, 'delegacion_encargada_bueno'))
            ?: $this->cleanText(Arr::get($lead, 'delegacion_encargada'))
            ?: $this->cleanText(Arr::get($lead, 'delegacion'));

        if ($fallbackValue !== null) {
            $delegation = $this->findMasterDelegationByNameOrAlias($fallbackValue);

            if ($delegation !== null) {
                return [
                    'type' => 'Delegación',
                    'delegation_name' => $delegation->delegation_name,
                    'commercial_group' => $delegation->commercial_group,
                    'mapping_found' => true,
                ];
            }

            return [
                'type' => null,
                'delegation_name' => null,
                'commercial_group' => null,
                'mapping_found' => false,
            ];
        }

        if ($isExposition) {
            $ownerDelegation = $this->cleanText(Arr::get($lead, 'owner_delegation'));

            if ($ownerDelegation !== null) {
                $delegation = $this->findMasterDelegationByNameOrAlias($ownerDelegation);

                if ($delegation !== null) {
                    return [
                        'type' => 'Delegación',
                        'delegation_name' => $delegation->delegation_name,
                        'commercial_group' => $delegation->commercial_group,
                        'mapping_found' => true,
                    ];
                }
            }
        }

        return $this->emptyDelegation();
    }

    private function findMasterDelegationByNameOrAlias(string $value): ?MasterDelegation
    {
        $normalizedValue = $this->normalizeComparable($value);

        return MasterDelegation::query()
            ->where('is_active', true)
            ->get()
            ->first(function (MasterDelegation $delegation) use ($normalizedValue) {
                return $this->normalizeComparable($delegation->delegation_name) === $normalizedValue
                    || $this->normalizeComparable(str_replace('HR MOTOR ', '', $delegation->delegation_name)) === $normalizedValue;
            });
    }

    private function resolveDataQuality(
        array $lead,
        string $channel,
        string $portalOriginal,
        ?string $portalGroup,
        array $delegation,
        bool $isExposition
    ): array {
        if ($portalGroup === null) {
            return [
                'status' => 'warning',
                'issue' => 'Portal sin grupo portal',
            ];
        }

        if ($channel === 'Llamada' && blank(Arr::get($lead, 'delegacion_encargada_text'))) {
            return [
                'status' => 'warning',
                'issue' => 'Llamada sin delegación',
            ];
        }

        if ($channel === 'Llamada' && $delegation['mapping_found'] === false) {
            return [
                'status' => 'warning',
                'issue' => 'Delegación no reconocida',
            ];
        }

        if ($channel === 'Formulario' && blank(Arr::get($lead, 'remitente_lead'))) {
            if ($delegation['delegation_name'] !== null || $delegation['commercial_group'] !== null) {
                return [
                    'status' => 'warning',
                    'issue' => 'Formulario sin Remitente Lead',
                ];
            }

            return [
                'status' => 'warning',
                'issue' => 'Formulario sin Remitente Lead',
            ];
        }

        if ($channel === 'Formulario' && filled(Arr::get($lead, 'remitente_lead')) && $delegation['mapping_found'] === false) {
            return [
                'status' => 'warning',
                'issue' => 'Remitente Lead no mapeado',
            ];
        }

        if ($isExposition && blank(Arr::get($lead, 'owner_name')) && blank(Arr::get($lead, 'owner_delegation'))) {
            return [
                'status' => 'warning',
                'issue' => 'Exposición sin propietario/delegación trabajador',
            ];
        }

        return [
            'status' => 'ok',
            'issue' => null,
        ];
    }

    public function isExposition(string $portalOriginal): bool
    {
        return Str::lower(trim($portalOriginal)) === Str::lower('Exposición');
    }

    public function isConverted(array $lead): bool
    {
        $status = $this->normalizeComparable((string) Arr::get($lead, 'status'));

        return $status === $this->normalizeComparable('Convertido');
    }

    public function isDiscarded(array $lead): bool
    {
        $status = $this->normalizeComparable((string) Arr::get($lead, 'status'));

        return $status === $this->normalizeComparable('Descartado');
    }

    public function isPotential(array $lead): bool
    {
        $status = $this->normalizeComparable((string) Arr::get($lead, 'status'));

        return $status === $this->normalizeComparable('Potencial');
    }

    public function hasTaskEvent(array $lead): bool
    {
        return filled(Arr::get($lead, 'first_task_event_at')) || filled(Arr::get($lead, 'last_task_event_at'));
    }

    public function hasRecentFollowUp(array $lead): bool
    {
        $last = Arr::get($lead, 'last_task_event_at');

        if (blank($last)) {
            return false;
        }

        return now()->diffInDays($last) <= 3;
    }

    private function minutesBetween(mixed $from, mixed $to): ?int
    {
        if (blank($from) || blank($to)) {
            return null;
        }

        try {
            return (int) abs(now()->parse($from)->diffInMinutes(now()->parse($to)));
        } catch (\Throwable) {
            return null;
        }
    }

    private function emptyDelegation(): array
    {
        return [
            'type' => null,
            'delegation_name' => null,
            'commercial_group' => null,
            'mapping_found' => false,
            'fallback_used' => false,
        ];
    }

    private function cleanText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function cleanEmail(mixed $value): ?string
    {
        $value = $this->cleanText($value);

        return $value !== null ? mb_strtolower($value) : null;
    }

    private function normalizeComparable(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replace(['hr motor ', '.', ',', '-', '_', '/'], [''])
            ->replaceMatches('/\s+/', '')
            ->toString();
    }
}