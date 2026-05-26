<?php

namespace App\Services\Reports\Calls;

use Illuminate\Support\Str;

class CallClassificationRules
{
    public const CUSTOMER_SERVICE_LABEL = 'Atención al Cliente';
    public const CONTACT_CENTER_LABEL = 'Contact Center';
    public const OVERFLOW_REASON_PORTAL_ATTENDED_BY_SUPPORT = 'portal_attended_by_support_team';

    public function normalizeName(?string $name): string
    {
        return Str::of($this->cleanUserName($name))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    public function isSystemIdentity(?string $name, ?string $profile): bool
    {
        $nameKey = $this->normalizeName($name);
        $profile = (string) $profile;

        return in_array($nameKey, ['carlos torres', 'platform integration user', 'api user'], true)
            || str_contains($profile, 'System Administrator')
            || str_contains($profile, 'Administrator');
    }

    public function isCustomerServiceSpecialName(?string $name): bool
    {
        $compact = str_replace(' ', '', $this->normalizeName($name));

        return in_array($compact, ['vanessasanjuan', 'vanesasanjuan', 'callcenterfontellas'], true);
    }

    public function effectiveTeam(?string $team, ?string $name = null, ?string $profile = null): string
    {
        if ($this->isSystemIdentity($name, $profile)) {
            return 'system';
        }

        if ($this->isCustomerServiceSpecialName($name)) {
            return 'customer_service';
        }

        return match ($team) {
            'commercial', 'customer_service', 'contact_center', 'appraiser', 'system' => $team,
            default => 'appraiser',
        };
    }

    public function effectiveOrigin(?string $origin, ?string $portalesRaw): string
    {
        $portalKey = $this->normalizeName($portalesRaw);

        if ($portalesRaw === null || $portalKey === 'llamada directa' || $origin === 'switchboard') {
            return 'commercial_direct';
        }

        return $origin === 'commercial_direct' ? 'commercial_direct' : 'portal';
    }

    public function isOperationalTeam(?string $team): bool
    {
        return in_array($team, ['commercial', 'customer_service', 'contact_center', 'appraiser'], true);
    }

    public function effectiveDelegationZone(?string $team, ?string $delegation, ?string $zone): array
    {
        return match ($team) {
            'customer_service' => [
                'delegation' => self::CUSTOMER_SERVICE_LABEL,
                'zone' => self::CUSTOMER_SERVICE_LABEL,
            ],
            'contact_center' => [
                'delegation' => self::CONTACT_CENTER_LABEL,
                'zone' => self::CONTACT_CENTER_LABEL,
            ],
            default => [
                'delegation' => filled($delegation) ? $delegation : 'Sin clasificar',
                'zone' => filled($zone) ? $zone : 'Sin clasificar',
            ],
        };
    }

    public function userGroupKey(
        ?string $team,
        ?string $operationalUserId,
        ?string $operationalUserName,
        ?string $destinationAgentName,
        ?string $ownerName,
        ?string $ownerProfile,
    ): string {
        if ($this->isSystemIdentity($operationalUserName ?: $ownerName, $ownerProfile)) {
            return 'system';
        }

        $nameKey = $this->normalizedCanonicalUserKey($operationalUserName, $destinationAgentName, $ownerName);

        if ($nameKey !== '') {
            return ($team ?: 'unknown').'|'.$nameKey;
        }

        return ($team ?: 'unknown').'|'.($operationalUserId ?: 'sin-clasificar');
    }

    public function normalizedUserKey(?string ...$names): string
    {
        return $this->normalizedCanonicalUserKey(...$names);
    }

    public function canonicalUserName(?string ...$names): string
    {
        $key = $this->normalizedCanonicalUserKey(...$names);

        return match ($key) {
            '' => 'Sin clasificar',
            'vanesa sanjuan' => 'Vanessa SanJuan',
            'callcenter fontellas' => 'Callcenter Fontellas',
            default => Str::of($key)->title()->toString(),
        };
    }

    public function isOverflow(?string $origin, ?string $status, ?string $portal, ?string $team): bool
    {
        return $origin === 'portal'
            && $status === 'answered'
            && in_array($team, ['contact_center', 'customer_service'], true)
            && ! $this->isOverflowExcludedPortal($portal);
    }

    public function overflowReason(?string $origin, ?string $status, ?string $portal, ?string $team): ?string
    {
        return $this->isOverflow($origin, $status, $portal, $team)
            ? self::OVERFLOW_REASON_PORTAL_ATTENDED_BY_SUPPORT
            : null;
    }

    public function isOverflowExcludedPortal(?string $portal): bool
    {
        $portalKey = $this->normalizeName($portal);

        return in_array($portalKey, ['web', 'google maps'], true);
    }

    public function displayUserName(?string $operationalUserName, ?string $destinationAgentName, ?string $ownerName): string
    {
        foreach ([$operationalUserName, $destinationAgentName, $ownerName] as $name) {
            $clean = $this->cleanUserName($name);

            if ($clean !== '') {
                return $clean;
            }
        }

        return 'Sin clasificar';
    }

    public function cleanUserName(?string $name): string
    {
        return Str::of((string) $name)
            ->replaceMatches('/^\s*\[?\s*AG\d+\s*\]?\s*[-:]?\s*/iu', '')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function normalizedCanonicalUserKey(?string ...$names): string
    {
        foreach ($names as $name) {
            $key = $this->normalizeName($name);
            $compact = str_replace(' ', '', $key);

            if ($compact === '') {
                continue;
            }

            return match ($compact) {
                'vanessasanjuan', 'vanesasanjuan' => 'vanesa sanjuan',
                'callcenterfontellas' => 'callcenter fontellas',
                default => $key,
            };
        }

        return '';
    }
}
