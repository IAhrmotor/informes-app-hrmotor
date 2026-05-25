<?php

namespace App\Console\Commands;

use App\Models\SalesforceCall;
use App\Services\Reports\Calls\CallClassificationRules;
use App\Services\Reports\Calls\CallPortalNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ReprocessCallsClassificationCommand extends Command
{
    protected $signature = 'reports:reprocess-calls-classification';

    protected $description = 'Recalcula origen, portal y equipo operativo de llamadas ya sincronizadas.';

    public function handle(CallPortalNormalizer $portalNormalizer, CallClassificationRules $rules): int
    {
        $before = $this->originCounts();
        $updated = 0;

        SalesforceCall::query()
            ->orderBy('id')
            ->chunkById(1000, function ($calls) use ($portalNormalizer, $rules, &$updated): void {
                foreach ($calls as $call) {
                    $portal = $portalNormalizer->normalize($call->portales_raw);
                    $origin = $portal['origin'];
                    $duration = is_numeric($call->call_duration_seconds)
                        ? (int) $call->call_duration_seconds
                        : (int) $call->parsed_duration_seconds;

                    $call->forceFill([
                        'call_origin' => $origin,
                        'portal_resolved' => $portal['portal'],
                        'portal_resolution_source' => $portal['source'],
                        'adjusted_duration_seconds' => max(0, $duration - ($origin === 'commercial_direct' ? 5 : 10)),
                        'operational_team' => $rules->effectiveTeam(
                            $call->operational_team,
                            $call->operational_user_name ?: $call->owner_name,
                            $call->owner_profile_name,
                        ),
                        'owner_team' => $rules->effectiveTeam(
                            $call->owner_team,
                            $call->owner_name,
                            $call->owner_profile_name,
                        ),
                    ])->save();

                    $updated++;
                }
            });

        $this->invalidateDashboardCache();
        $after = $this->originCounts();

        $this->info('Reproceso de clasificacion de llamadas completado.');
        $this->line('Llamadas actualizadas: '.$updated);
        $this->newLine();
        $this->table(['Origen', 'Antes', 'Despues'], collect(['commercial_direct', 'portal', 'switchboard', 'otros'])
            ->map(fn (string $origin) => [
                $origin,
                $before[$origin] ?? 0,
                $after[$origin] ?? 0,
            ])
            ->all());

        return self::SUCCESS;
    }

    private function originCounts(): array
    {
        $counts = SalesforceCall::query()
            ->selectRaw('call_origin, count(*) as total')
            ->groupBy('call_origin')
            ->pluck('total', 'call_origin')
            ->all();

        $known = ['commercial_direct', 'portal', 'switchboard'];
        $others = 0;

        foreach ($counts as $origin => $total) {
            if (! in_array((string) $origin, $known, true)) {
                $others += (int) $total;
            }
        }

        return [
            'commercial_direct' => (int) ($counts['commercial_direct'] ?? 0),
            'portal' => (int) ($counts['portal'] ?? 0),
            'switchboard' => (int) ($counts['switchboard'] ?? 0),
            'otros' => $others,
        ];
    }

    private function invalidateDashboardCache(): void
    {
        Cache::forever('salesforce_calls_dashboard_cache_version', ((int) Cache::get('salesforce_calls_dashboard_cache_version', 1)) + 1);
    }
}
