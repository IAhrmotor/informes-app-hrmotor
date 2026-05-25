<?php

namespace App\Services\Reports\Calls;

use App\Models\CallAgentMapping;
use App\Models\SalesforceUser;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CallAgentResolver
{
    private const COMMERCIAL_PROFILES = [
        'Compra/Venta',
        'Comerciales Partner Community',
    ];

    private ?Collection $mappings = null;
    private ?Collection $users = null;

    public function __construct(
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly CallClassificationRules $rules,
    ) {
    }

    public function resolve(array $owner, array $parsed, string $origin): array
    {
        $ownerUser = $this->userById($owner['id'] ?? null);
        $ownerName = $owner['name'] ?? data_get($ownerUser, 'name');
        $ownerProfile = $owner['profile_name'] ?? data_get($ownerUser, 'profile_name');
        $ownerTeam = $this->teamForUser($owner['id'] ?? null, $ownerName, $ownerProfile);

        $candidate = null;

        if ($origin !== 'commercial_direct') {
            $candidate = $this->mappingForParsed($parsed);
        }

        if ($candidate) {
            $operationalUser = $candidate['salesforce_user_id'] ? $this->userById($candidate['salesforce_user_id']) : null;

            return $this->result(
                operationalUserId: $candidate['salesforce_user_id'],
                operationalUserName: $candidate['user_name'],
                operationalTeam: $candidate['team_type'],
                ownerTeam: $ownerTeam,
                delegationSource: $operationalUser ?: $ownerUser,
            );
        }

        return $this->result(
            operationalUserId: $owner['id'] ?? data_get($ownerUser, 'salesforce_id'),
            operationalUserName: $ownerName ?: 'Sin clasificar',
            operationalTeam: $ownerTeam,
            ownerTeam: $ownerTeam,
            delegationSource: $ownerUser,
        );
    }

    public function normalizeName(?string $name): string
    {
        return $this->rules->normalizeName($name);
    }

    private function mappingForParsed(array $parsed): ?array
    {
        $code = filled($parsed['destination_agent_code'] ?? null) ? Str::upper((string) $parsed['destination_agent_code']) : null;
        $name = $this->normalizeName($parsed['destination_agent_name'] ?? null);

        if ($code) {
            $mapping = $this->mappings()->firstWhere('agent_code', $code);
            if ($mapping) {
                return $mapping;
            }
        }

        if ($name !== '') {
            $mapping = $this->mappings()->firstWhere('normalized_name', $name);
            if ($mapping) {
                return $mapping;
            }

            if ($this->rules->isCustomerServiceSpecialName($parsed['destination_agent_name'] ?? null)) {
                return [
                    'salesforce_user_id' => null,
                    'agent_code' => $code,
                    'user_name' => $parsed['destination_agent_name'],
                    'normalized_name' => $name,
                    'team_type' => 'customer_service',
                ];
            }
        }

        return null;
    }

    private function teamForUser(?string $id, ?string $name, ?string $profile): string
    {
        $nameKey = $this->normalizeName($name);
        $profile = (string) $profile;

        if (filled($id)) {
            $mapping = $this->mappings()->firstWhere('salesforce_user_id', $id);
            if ($mapping) {
                return $mapping['team_type'];
            }
        }

        if ($this->rules->isSystemIdentity($name, $profile)) {
            return 'system';
        }

        if ($this->rules->isCustomerServiceSpecialName($name)) {
            return 'customer_service';
        }

        if (in_array($profile, self::COMMERCIAL_PROFILES, true)) {
            return 'commercial';
        }

        $mapping = $this->mappings()->firstWhere('normalized_name', $nameKey);

        return $mapping['team_type'] ?? 'appraiser';
    }

    private function result(
        ?string $operationalUserId,
        ?string $operationalUserName,
        string $operationalTeam,
        string $ownerTeam,
        ?array $delegationSource,
    ): array {
        $delegation = $this->delegationNormalizer->normalize(data_get($delegationSource, 'user_delegation'));

        return [
            'operational_user_id' => $operationalUserId,
            'operational_user_name' => $operationalUserName ?: 'Sin clasificar',
            'operational_team' => $operationalTeam,
            'owner_team' => $ownerTeam,
            'delegation' => $delegation['delegation'] ?? LeadDelegationNormalizer::UNCLASSIFIED,
            'zone' => $delegation['zone'] ?? LeadDelegationNormalizer::UNCLASSIFIED,
        ];
    }

    private function mappings(): Collection
    {
        return $this->mappings ??= CallAgentMapping::query()
            ->where('active', true)
            ->get()
            ->map(fn (CallAgentMapping $mapping) => [
                'salesforce_user_id' => $mapping->salesforce_user_id,
                'agent_code' => $mapping->agent_code ? Str::upper($mapping->agent_code) : null,
                'user_name' => $mapping->user_name,
                'normalized_name' => $mapping->normalized_name ?: $this->normalizeName($mapping->user_name),
                'team_type' => $mapping->team_type,
            ]);
    }

    private function userById(?string $id): ?array
    {
        if (blank($id)) {
            return null;
        }

        return $this->users()->get($id);
    }

    private function users(): Collection
    {
        return $this->users ??= SalesforceUser::query()
            ->get()
            ->keyBy('salesforce_id')
            ->map(fn (SalesforceUser $user) => [
                'salesforce_id' => $user->salesforce_id,
                'name' => $user->name,
                'profile_name' => $user->profile_name,
                'user_delegation' => $user->user_delegation,
            ]);
    }
}
