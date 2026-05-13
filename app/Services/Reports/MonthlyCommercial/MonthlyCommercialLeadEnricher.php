<?php

namespace App\Services\Reports\MonthlyCommercial;

use App\Models\MasterDelegation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Str;

class MonthlyCommercialLeadEnricher
{
    public const EXCLUDED_USER_IDS = [
        '0052X00000AP4U5QAL',
        '0057R00000AKkz0QAD',
        '0057R00000CQGZaQAP',
    ];

    public function enrich(mixed $lead, mixed $summary = null, array $commercialUsersById = [], ?CarbonInterface $now = null): array
    {
        $now = $now ? CarbonImmutable::parse($now) : CarbonImmutable::now();
        $status = $this->clean($this->leadValue($lead, 'status', 'Status'));
        $statusKey = $this->normalizeComparable((string) $status);

        $createdDate = $this->parseDate($this->leadValue($lead, 'created_date', 'CreatedDate'));
        $assignedAt = $this->parseDate($this->leadValue($lead, 'fecha_asignacion', 'Fecha_Asignacion__c'));
        $firstActivityAt = $this->parseDate($this->summaryValue($summary, 'fecha_primer_contacto'));
        $lastActivityAt = $this->parseDate($this->summaryValue($summary, 'fecha_ultima_actividad'));

        $isConverted = $statusKey === $this->normalizeComparable('Convertido');
        $isDiscarded = $statusKey === $this->normalizeComparable('Descartado');
        $isPotential = $statusKey === $this->normalizeComparable('Potencial');

        $responsible = $this->resolveResponsible($lead, $isConverted, $isDiscarded);
        $firstActivityOwner = [
            'id' => $this->clean($this->summaryValue($summary, 'primer_contacto_owner_id')),
            'name' => $this->clean($this->summaryValue($summary, 'primer_contacto_owner_name')),
        ];
        $realManager = $this->resolveRealManager($lead, $firstActivityOwner, $isConverted, $isDiscarded);

        $totalActivities = (int) ($this->summaryValue($summary, 'total_actividades') ?? 0);
        $hasActivity = $totalActivities > 0 || $firstActivityAt !== null || $lastActivityAt !== null;
        $lastActivityOlderThan3Days = $lastActivityAt !== null && $lastActivityAt->lt($now->subDays(3));

        $creationAssignmentMinutes = $this->diffMinutes($createdDate, $assignedAt);
        $assignmentFirstMinutes = $this->diffMinutes($assignedAt, $firstActivityAt);
        $creationFirstMinutes = $this->diffMinutes($createdDate, $firstActivityAt);

        $delegation = $this->resolveDelegation($lead);
        $ownerId = $this->clean($this->leadValue($lead, 'owner_id', 'OwnerId'));

        return [
            'lead_salesforce_id' => $this->clean($this->leadValue($lead, 'salesforce_id', 'Id')),
            'nombre' => $this->clean($this->leadValue($lead, 'name', 'Name')),
            'status' => $status,
            'status_normalizado' => $statusKey,
            'es_convertido' => $isConverted,
            'es_descartado' => $isDiscarded,
            'es_potencial' => $isPotential,
            'owner_id' => $ownerId,
            'owner_name' => $this->clean($this->leadValue($lead, 'owner_name', 'Owner.Name')),
            'persona_que_trabajo_id' => $this->clean($this->leadValue($lead, 'persona_que_trabajo_id', 'Persona_que_trabaj__c')),
            'persona_que_trabajo_name' => $this->clean($this->leadValue($lead, 'persona_que_trabajo_name', 'Persona_que_trabaj__r.Name')),
            'propietario_descarte_id' => $this->clean($this->leadValue($lead, 'propietario_descarte_id', 'Propietario_cuando_se_descarto__c')),
            'propietario_descarte_name' => $this->clean($this->leadValue($lead, 'propietario_descarte_name', 'Propietario_cuando_se_descarto__r.Name')),
            'responsable_id' => $responsible['id'],
            'responsable_nombre' => $responsible['name'] ?? 'Sin responsable',
            'responsable_sin_resolver' => blank($responsible['id']),
            'responsable_es_comercial' => $this->isCommercialUser($responsible['id'], $commercialUsersById),
            'responsable_excluido' => in_array($responsible['id'], self::EXCLUDED_USER_IDS, true),
            'gestor_real_id' => $realManager['id'],
            'gestor_real_nombre' => $realManager['name'] ?? 'Sin gestor',
            'gestor_distinto_owner' => filled($realManager['id']) && filled($ownerId) && $realManager['id'] !== $ownerId,
            'trabajado_distinto_owner' => filled($this->leadValue($lead, 'persona_que_trabajo_id', 'Persona_que_trabaj__c'))
                && filled($ownerId)
                && $this->leadValue($lead, 'persona_que_trabajo_id', 'Persona_que_trabaj__c') !== $ownerId,
            'descarte_distinto_owner' => filled($this->leadValue($lead, 'propietario_descarte_id', 'Propietario_cuando_se_descarto__c'))
                && filled($ownerId)
                && $this->leadValue($lead, 'propietario_descarte_id', 'Propietario_cuando_se_descarto__c') !== $ownerId,
            'fuente_original' => $this->clean($this->leadValue($lead, 'fuente_origen', 'LEA_SEL_Fuente_Origen__c')) ?? 'Desconocida',
            'medio' => $this->clean($this->leadValue($lead, 'medio_origen', 'LEA_SEL_Medio_Origen__c')) ?? 'Desconocido',
            'portal' => $this->clean($this->leadValue($lead, 'portal_text', 'Portal_Text__c')) ?? 'Desconocido',
            'fuente' => $this->clean($this->leadValue($lead, 'portal_text', 'Portal_Text__c')) ?? 'Desconocido',
            'delegacion_original' => $delegation['original'],
            'delegacion_nombre' => $delegation['delegation_name'],
            'delegacion_grupo' => $delegation['commercial_group'],
            'delegacion_mapeada' => $delegation['mapped'],
            'fecha_creacion' => $createdDate?->toIso8601String(),
            'fecha_asignacion' => $assignedAt?->toIso8601String(),
            'fecha_primer_contacto' => $firstActivityAt?->toIso8601String(),
            'fecha_ultima_actividad' => $lastActivityAt?->toIso8601String(),
            'total_actividades' => $totalActivities,
            'total_tasks' => (int) ($this->summaryValue($summary, 'total_tasks') ?? 0),
            'total_events' => (int) ($this->summaryValue($summary, 'total_events') ?? 0),
            'primer_contacto_activity_id' => $this->clean($this->summaryValue($summary, 'primer_contacto_activity_id')),
            'primer_contacto_tipo' => $this->clean($this->summaryValue($summary, 'primer_contacto_tipo')),
            'primer_contacto_subject' => $this->clean($this->summaryValue($summary, 'primer_contacto_subject')),
            'primer_contacto_owner_id' => $firstActivityOwner['id'],
            'primer_contacto_owner_name' => $firstActivityOwner['name'],
            'primer_contacto_created_by_id' => $this->clean($this->summaryValue($summary, 'primer_contacto_created_by_id')),
            'primer_contacto_created_by_name' => $this->clean($this->summaryValue($summary, 'primer_contacto_created_by_name')),
            'tiene_task_event_registrada' => $hasActivity,
            'dias_desde_ultima_task_event' => $lastActivityAt ? (int) floor($lastActivityAt->diffInDays($now)) : null,
            'potencial_sin_ninguna_task_event' => $isPotential && ! $hasActivity,
            'potencial_con_ultima_task_mayor_3_dias' => $isPotential && $hasActivity && $lastActivityOlderThan3Days,
            'potencial_sin_seguimiento_mayor_3_dias' => $isPotential && (! $hasActivity || $lastActivityOlderThan3Days),
            'tiempo_creacion_asignacion_minutos' => $creationAssignmentMinutes,
            'tiempo_asignacion_primera_actividad_minutos' => $assignmentFirstMinutes,
            'tiempo_creacion_primera_actividad_minutos' => $creationFirstMinutes,
            'tiempo_creacion_asignacion_horas' => $this->minutesToHours($creationAssignmentMinutes),
            'tiempo_asignacion_primera_actividad_horas' => $this->minutesToHours($assignmentFirstMinutes),
            'tiempo_creacion_primera_actividad_horas' => $this->minutesToHours($creationFirstMinutes),
            'primera_actividad_antes_asignacion' => $assignedAt !== null && $firstActivityAt !== null && $firstActivityAt->lt($assignedAt),
            'fecha_asignacion_antes_creacion' => $createdDate !== null && $assignedAt !== null && $assignedAt->lt($createdDate),
        ];
    }

    private function resolveResponsible(mixed $lead, bool $isConverted, bool $isDiscarded): array
    {
        if ($isConverted) {
            return $this->firstUserPair($lead, [
                ['persona_que_trabajo_id', 'Persona_que_trabaj__c', 'persona_que_trabajo_name', 'Persona_que_trabaj__r.Name'],
                ['owner_id', 'OwnerId', 'owner_name', 'Owner.Name'],
            ]);
        }

        if ($isDiscarded) {
            return $this->firstUserPair($lead, [
                ['propietario_descarte_id', 'Propietario_cuando_se_descarto__c', 'propietario_descarte_name', 'Propietario_cuando_se_descarto__r.Name'],
                ['persona_que_trabajo_id', 'Persona_que_trabaj__c', 'persona_que_trabajo_name', 'Persona_que_trabaj__r.Name'],
                ['owner_id', 'OwnerId', 'owner_name', 'Owner.Name'],
            ]);
        }

        return $this->firstUserPair($lead, [
            ['owner_id', 'OwnerId', 'owner_name', 'Owner.Name'],
        ]);
    }

    private function resolveRealManager(mixed $lead, array $firstActivityOwner, bool $isConverted, bool $isDiscarded): array
    {
        if ($isConverted) {
            return $this->firstUserPair($lead, [
                ['persona_que_trabajo_id', 'Persona_que_trabaj__c', 'persona_que_trabajo_name', 'Persona_que_trabaj__r.Name'],
            ], [
                $firstActivityOwner,
                $this->firstUserPair($lead, [['owner_id', 'OwnerId', 'owner_name', 'Owner.Name']]),
            ]);
        }

        if ($isDiscarded) {
            return $this->firstUserPair($lead, [
                ['propietario_descarte_id', 'Propietario_cuando_se_descarto__c', 'propietario_descarte_name', 'Propietario_cuando_se_descarto__r.Name'],
                ['persona_que_trabajo_id', 'Persona_que_trabaj__c', 'persona_que_trabajo_name', 'Persona_que_trabaj__r.Name'],
            ], [
                $firstActivityOwner,
                $this->firstUserPair($lead, [['owner_id', 'OwnerId', 'owner_name', 'Owner.Name']]),
            ]);
        }

        return $this->firstResolvedPair([
            $firstActivityOwner,
            $this->firstUserPair($lead, [['owner_id', 'OwnerId', 'owner_name', 'Owner.Name']]),
        ]);
    }

    private function firstUserPair(mixed $lead, array $fields, array $fallbacks = []): array
    {
        $pairs = [];

        foreach ($fields as [$snakeId, $sfId, $snakeName, $sfName]) {
            $pairs[] = [
                'id' => $this->clean($this->leadValue($lead, $snakeId, $sfId)),
                'name' => $this->clean($this->leadValue($lead, $snakeName, $sfName)),
            ];
        }

        return $this->firstResolvedPair(array_merge($pairs, $fallbacks));
    }

    private function firstResolvedPair(array $pairs): array
    {
        foreach ($pairs as $pair) {
            if (filled($pair['id'] ?? null)) {
                return [
                    'id' => $pair['id'],
                    'name' => $pair['name'] ?? null,
                ];
            }
        }

        return ['id' => null, 'name' => null];
    }

    private function resolveDelegation(mixed $lead): array
    {
        $original = $this->clean($this->leadValue($lead, 'delegacion_encargada_text', 'Delegacion_Encargada_Text__c'));

        if ($original === null) {
            return [
                'original' => null,
                'delegation_name' => 'Sin delegacion',
                'commercial_group' => 'Sin mapear',
                'mapped' => false,
            ];
        }

        $normalized = $this->normalizeComparable($original);

        $match = MasterDelegation::query()
            ->where('is_active', true)
            ->get()
            ->first(function (MasterDelegation $delegation) use ($normalized) {
                return $this->normalizeComparable($delegation->delegation_name) === $normalized
                    || $this->normalizeComparable(str_replace('HR MOTOR ', '', $delegation->delegation_name)) === $normalized
                    || $this->normalizeComparable($delegation->commercial_group) === $normalized;
            });

        if ($match === null) {
            return [
                'original' => $original,
                'delegation_name' => 'Sin delegacion',
                'commercial_group' => 'Sin mapear',
                'mapped' => false,
            ];
        }

        return [
            'original' => $original,
            'delegation_name' => $match->delegation_name,
            'commercial_group' => $match->commercial_group,
            'mapped' => true,
        ];
    }

    private function isCommercialUser(?string $userId, array $commercialUsersById): bool
    {
        if (blank($userId)) {
            return false;
        }

        return array_key_exists($userId, $commercialUsersById) || in_array($userId, $commercialUsersById, true);
    }

    private function leadValue(mixed $lead, string $snakeKey, string $salesforceKey): mixed
    {
        return data_get($lead, $snakeKey) ?? data_get($lead, $salesforceKey);
    }

    private function summaryValue(mixed $summary, string $key): mixed
    {
        return data_get($summary, $key);
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof CarbonInterface || $value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function diffMinutes(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return (int) abs($from->diffInMinutes($to));
    }

    private function minutesToHours(?int $minutes): ?float
    {
        return $minutes === null ? null : round($minutes / 60, 2);
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
