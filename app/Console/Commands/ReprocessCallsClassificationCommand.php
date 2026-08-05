<?php

namespace App\Console\Commands;

use App\Models\SalesforceCall;
use App\Models\SalesforceCallClassificationHistory;
use App\Models\SalesforceUser;
use App\Services\Reports\Calls\CallClassificationRules;
use App\Services\Reports\Calls\CallDescriptionParser;
use App\Services\Reports\Calls\CallPortalNormalizer;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;

class ReprocessCallsClassificationCommand extends Command
{
    protected $signature = 'reports:reprocess-calls-classification
        {--from= : Inicio inclusivo YYYY-MM-DD}
        {--to= : Fin inclusivo YYYY-MM-DD}
        {--dry-run : Simula sin modificar datos}
        {--reason= : Motivo auditable obligatorio al ejecutar}';

    protected $description = 'Recalcula origen, portal, equipo operativo, delegacion y zona de llamadas ya sincronizadas.';

    public function handle(
        CallPortalNormalizer $portalNormalizer,
        CallDescriptionParser $parser,
        CallClassificationRules $rules,
        LeadDelegationNormalizer $delegationNormalizer,
    ): int
    {
        $from = $this->dateOption('from');
        $to = $this->dateOption('to');
        if ($from === null || $to === null || $from->greaterThan($to)) {
            $this->error('Debes indicar un periodo explícito válido con --from=YYYY-MM-DD --to=YYYY-MM-DD.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $reason = trim((string) $this->option('reason'));
        if (! $dryRun && mb_strlen($reason) < 10) {
            $this->error('Al ejecutar cambios debes indicar --reason con al menos 10 caracteres.');

            return self::FAILURE;
        }
        $before = $this->originCounts();
        $updated = 0;
        $users = $this->salesforceUsers();

        SalesforceCall::query()
            ->where('created_date', '>=', $from->startOfDay())
            ->where('created_date', '<', $to->addDay()->startOfDay())
            ->orderBy('id')
            ->chunkById(1000, function ($calls) use ($portalNormalizer, $parser, $rules, $delegationNormalizer, $users, &$updated, $dryRun, $reason): void {
                $updates = [];
                $history = [];

                foreach ($calls as $call) {
                    $parsed = $parser->parse($call->description);
                    $portal = $portalNormalizer->normalize($call->portales_raw);
                    $origin = $portal['origin'];
                    $classificationResult = $parsed['result_raw'] ?? $call->result_raw;
                    $callStatus = $this->classifyStatus($classificationResult, $call->call_status);
                    $duration = is_numeric($call->call_duration_seconds)
                        ? (int) $call->call_duration_seconds
                        : (int) $call->parsed_duration_seconds;

                    $team = $rules->effectiveTeam(
                        $call->operational_team,
                        $call->operational_user_name ?: $call->owner_name,
                        $call->owner_profile_name,
                    );
                    $ownerTeam = $rules->effectiveTeam(
                        $call->owner_team,
                        $call->owner_name,
                        $call->owner_profile_name,
                    );
                    $delegationZone = $this->delegationZone($call, $team, $users, $delegationNormalizer, $rules);
                    $normalizedUserKey = $rules->normalizedUserKey(
                        $call->operational_user_name,
                        $call->destination_agent_name,
                        $call->owner_name,
                    );
                    $pollValue = $parsed['poll_value'];
                    $isOverflow = $rules->isOverflow($origin, $callStatus, $portal['portal'], $team, $pollValue, $call->result_raw);

                    $updates[] = [
                        'salesforce_id' => $call->salesforce_id,
                        'included_in_dashboard' => filled($call->call_object),
                        'dashboard_exclusion_reason' => filled($call->call_object) ? null : 'missing_call_object',
                        'classification_rule_version' => CallClassificationRules::VERSION,
                        'classified_at' => now(),
                        'call_origin' => $origin,
                        'portal_resolved' => $portal['portal'],
                        'portal_resolution_source' => $portal['source'],
                        'poll_value' => $pollValue,
                        'call_status' => $callStatus,
                        'is_answered' => $callStatus === 'answered',
                        'is_lost' => $callStatus !== 'answered',
                        'adjusted_duration_seconds' => max(0, $duration - ($origin === 'commercial_direct' ? 5 : 10)),
                        'operational_team' => $team,
                        'normalized_user_key' => $normalizedUserKey,
                        'owner_team' => $ownerTeam,
                        'delegation' => $delegationZone['delegation'],
                        'zone' => $delegationZone['zone'],
                        'is_overflow' => $isOverflow,
                        'overflow_reason' => $rules->overflowReason($origin, $callStatus, $portal['portal'], $team, $pollValue, $call->result_raw),
                        'parse_debug' => json_encode(array_merge($call->parse_debug ?? [], [
                            'reprocessed_poll_value' => $pollValue,
                        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ];
                    $new = end($updates);
                    $history[] = [
                        'salesforce_call_id' => $call->id,
                        'task_salesforce_id' => $call->salesforce_id,
                        'previous_rule_version' => $call->classification_rule_version,
                        'new_rule_version' => CallClassificationRules::VERSION,
                        'change_source' => 'manual_reprocess',
                        'reason' => $reason,
                        'raw_values' => json_encode([
                            'result_raw' => $call->result_raw,
                            'description' => $call->description,
                            'call_object' => $call->call_object,
                            'duration_salesforce' => $call->call_duration_seconds,
                            'duration_parsed' => $call->parsed_duration_seconds,
                            'portal_raw' => $call->portales_raw,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'previous_classification' => json_encode($this->classificationSnapshot($call), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'new_classification' => json_encode($this->classificationSnapshot((object) $new), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'classified_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($dryRun) {
                    $updated += count($updates);

                    return;
                }
                SalesforceCallClassificationHistory::query()->insert($history);
                SalesforceCall::query()->upsert($updates, ['salesforce_id'], [
                    'included_in_dashboard',
                    'dashboard_exclusion_reason',
                    'classification_rule_version',
                    'classified_at',
                    'call_origin',
                    'portal_resolved',
                    'portal_resolution_source',
                    'poll_value',
                    'call_status',
                    'is_answered',
                    'is_lost',
                    'adjusted_duration_seconds',
                    'operational_team',
                    'normalized_user_key',
                    'owner_team',
                    'delegation',
                    'zone',
                    'is_overflow',
                    'overflow_reason',
                    'parse_debug',
                    'updated_at',
                ]);

                $updated += count($updates);
            });

        if (! $dryRun) {
            $this->invalidateDashboardCache();
        }
        $after = $this->originCounts();

        $this->info($dryRun ? 'Simulación completada; no se modificaron datos.' : 'Reproceso de clasificación de llamadas completado.');
        $this->line('Procesadas: '.$updated);
        $this->newLine();
        $this->table(['Origen', 'Antes', 'Despues'], collect(['commercial_direct', 'portal', 'switchboard', 'otros'])
            ->map(fn (string $origin) => [
                $origin,
                $before[$origin] ?? 0,
                $after[$origin] ?? 0,
            ])
            ->all());
        $this->newLine();
        $this->table(['Metrica', 'Total'], $this->summaryCounts());
        $this->newLine();
        $this->table(['Equipo', 'Total'], $this->teamCounts());
        $this->line('Delegacion Sin clasificar restante: '.SalesforceCall::query()->where('delegation', LeadDelegationNormalizer::UNCLASSIFIED)->count());
        $this->line('Zona Sin clasificar restante: '.SalesforceCall::query()->where('zone', LeadDelegationNormalizer::UNCLASSIFIED)->count());

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        try {
            $value = trim((string) $this->option($name));

            return $value !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function classificationSnapshot(object $call): array
    {
        return collect([
            'call_status', 'is_answered', 'is_lost', 'is_overflow', 'overflow_reason',
            'call_origin', 'portal_resolved', 'operational_team', 'delegation', 'zone',
            'adjusted_duration_seconds', 'included_in_dashboard', 'dashboard_exclusion_reason',
        ])->mapWithKeys(fn (string $field): array => [$field => data_get($call, $field)])->all();
    }

    private function delegationZone(
        SalesforceCall $call,
        string $team,
        Collection $users,
        LeadDelegationNormalizer $delegationNormalizer,
        CallClassificationRules $rules,
    ): array {
        if (in_array($team, ['customer_service', 'contact_center'], true)) {
            return $rules->effectiveDelegationZone($team, null, null);
        }

        $user = $users->get($call->operational_user_id) ?: $users->get($call->owner_id);
        $normalized = $delegationNormalizer->normalize(data_get($user, 'user_delegation'));

        return $rules->effectiveDelegationZone(
            $team,
            $normalized['delegation'] ?? $call->delegation,
            $normalized['zone'] ?? $call->zone,
        );
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

    private function teamCounts(): array
    {
        return SalesforceCall::query()
            ->selectRaw('operational_team, count(*) as total')
            ->groupBy('operational_team')
            ->pluck('total', 'operational_team')
            ->pipe(fn ($counts) => collect(['commercial', 'customer_service', 'contact_center', 'appraiser', 'system', 'unclassified'])
                ->map(fn (string $team) => [$team, (int) ($counts[$team] ?? 0)])
                ->all());
    }

    private function summaryCounts(): array
    {
        return [
            ['procesadas', SalesforceCall::query()->count()],
            ['directas comercial', SalesforceCall::query()->where('call_origin', 'commercial_direct')->count()],
            ['portales', SalesforceCall::query()->where('call_origin', 'portal')->count()],
            ['answered', SalesforceCall::query()->where('call_status', 'answered')->count()],
            ['not_answered', SalesforceCall::query()->where('call_status', 'not_answered')->count()],
            ['abandoned', SalesforceCall::query()->whereRaw("UPPER(TRIM(COALESCE(result_raw, ''))) = 'ABANDONED'")->count()],
            ['overflows', SalesforceCall::query()->where('is_overflow', true)->count()],
            ['customer_service', SalesforceCall::query()->where('operational_team', 'customer_service')->count()],
            ['contact_center', SalesforceCall::query()->where('operational_team', 'contact_center')->count()],
            ['appraisers', SalesforceCall::query()->where('operational_team', 'appraiser')->count()],
            ['commercials', SalesforceCall::query()->where('operational_team', 'commercial')->count()],
            ['system', SalesforceCall::query()->where('operational_team', 'system')->count()],
            ['unclassified', SalesforceCall::query()->where('operational_team', 'unclassified')->count()],
        ];
    }

    private function classifyStatus(?string $resultRaw, ?string $currentStatus): string
    {
        $result = Str::of((string) $resultRaw)->upper()->trim()->toString();

        if ($result !== '') {
            return $result === 'ANSWERED' ? 'answered' : 'not_answered';
        }

        return $currentStatus === 'answered' ? 'answered' : 'not_answered';
    }

    private function salesforceUsers(): Collection
    {
        return SalesforceUser::query()
            ->get()
            ->keyBy('salesforce_id')
            ->map(fn (SalesforceUser $user) => [
                'salesforce_id' => $user->salesforce_id,
                'user_delegation' => $user->user_delegation,
            ]);
    }

    private function invalidateDashboardCache(): void
    {
        Cache::forever('salesforce_calls_dashboard_cache_version', ((int) Cache::get('salesforce_calls_dashboard_cache_version', 1)) + 1);
    }
}
