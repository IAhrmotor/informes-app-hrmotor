<?php

namespace App\Services\Reports\Calls;

use Illuminate\Support\Str;

class CallClassificationRules
{
    public function normalizeName(?string $name): string
    {
        return Str::of((string) $name)
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
}
