<?php

namespace App\Services\SeoAnalytics;

use App\Models\AnalyticalRuleSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SeoAnalyticalRuleSetService
{
    public function __construct(
        private readonly SeoAnalyticalMetricRegistry $registry,
        private readonly SeoAnalyticalRuleSetResolver $resolver,
        private readonly SeoAnalyticalEvaluationService $evaluations,
    ) {}

    /** @return array{active: AnalyticalRuleSet, history: mixed, definitions: array<int, array<string, mixed>>} */
    public function settings(): array
    {
        $active = $this->resolver->active();
        $rules = $active->rules->keyBy('metric_key');
        $definitions = collect($this->registry->metrics())
            ->map(fn (array $metric): array => $metric + ['rule' => $rules->get($metric['key'])])
            ->all();

        return [
            'active' => $active,
            'history' => AnalyticalRuleSet::query()
                ->where('module_key', SeoAnalyticalMetricRegistry::MODULE)
                ->orderByDesc('version_number')
                ->limit(10)
                ->get(),
            'definitions' => $definitions,
        ];
    }

    /** @param array<string, mixed> $submittedRules */
    public function createVersion(
        int $baseRuleSetId,
        int $baseVersionNumber,
        array $submittedRules,
        string $changeReason,
        int $actorId,
    ): AnalyticalRuleSet {
        $reason = trim($changeReason);
        if (mb_strlen($reason) < 1 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'change_reason' => 'El motivo del cambio debe tener entre 1 y 500 caracteres.',
            ]);
        }

        return DB::transaction(function () use (
            $baseRuleSetId,
            $baseVersionNumber,
            $submittedRules,
            $reason,
            $actorId,
        ): AnalyticalRuleSet {
            $activeSets = AnalyticalRuleSet::query()
                ->where('module_key', SeoAnalyticalMetricRegistry::MODULE)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();
            if ($activeSets->count() !== 1) {
                throw new RuntimeException('Debe existir exactamente un rule set SEO activo.');
            }

            $active = $activeSets->first();
            $active->load('rules');
            $this->resolver->assertComplete($active);
            if ($active->id !== $baseRuleSetId || $active->version_number !== $baseVersionNumber) {
                throw new SeoAnalyticalRuleSetConflictException;
            }

            $expectedKeys = $active->rules->pluck('metric_key')->sort()->values()->all();
            $submittedKeys = collect(array_keys($submittedRules))->sort()->values()->all();
            if ($submittedKeys !== $expectedKeys) {
                throw ValidationException::withMessages([
                    'rules' => 'Deben enviarse exclusivamente las seis metricas SEO configurables.',
                ]);
            }

            $newVersion = $active->version_number + 1;
            $now = now();
            $newRuleSet = AnalyticalRuleSet::query()->create([
                'module_key' => SeoAnalyticalMetricRegistry::MODULE,
                'version_number' => $newVersion,
                'version_key' => 'seo_rules_v'.$newVersion,
                'status' => 'active',
                'change_reason' => $reason,
                'created_by_report_user_id' => $actorId,
                'activated_at' => $now,
            ]);

            foreach ($active->rules as $rule) {
                $values = $this->validatedValues($rule->toArray(), $submittedRules[$rule->metric_key] ?? []);
                $newRuleSet->rules()->create([
                    'metric_key' => $rule->metric_key,
                    'comparison_mode' => $rule->comparison_mode,
                    'favorable_direction' => $rule->favorable_direction,
                    'threshold_unit' => $rule->threshold_unit,
                    ...$values,
                ]);
            }

            $active->update(['status' => 'superseded']);
            $newRuleSet->load('rules');
            $this->resolver->assertComplete($newRuleSet);
            $this->evaluations->evaluate(1, $newRuleSet);

            return $newRuleSet;
        });
    }

    /** @param array<string, mixed> $contract
     * @param  array<string, mixed>  $submitted
     * @return array<string, ?string>
     */
    private function validatedValues(array $contract, array $submitted): array
    {
        $required = ['observation_threshold', 'deviation_threshold', 'critical_threshold'];
        if ($contract['comparison_mode'] === 'relative_percent') {
            $required[] = 'minimum_baseline';
            $required[] = 'minimum_absolute_change';
        }

        if (collect(array_keys($submitted))->sort()->values()->all() !== collect($required)->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'rules.'.$contract['metric_key'] => 'La regla contiene campos ausentes o no editables.',
            ]);
        }

        $maximum = match ($contract['threshold_unit']) {
            'percentage_points' => 100,
            'positions', 'percent' => 1000,
            default => throw new RuntimeException('Unidad de threshold no soportada.'),
        };
        $observation = $this->decimal($submitted['observation_threshold'], 'Observacion', false, $maximum);
        $deviation = $this->decimal($submitted['deviation_threshold'], 'Desviacion', false, $maximum);
        $critical = $this->decimal($submitted['critical_threshold'], 'Critico', false, $maximum);
        if ((float) $deviation <= (float) $observation || (float) $critical <= (float) $deviation) {
            throw ValidationException::withMessages([
                'rules.'.$contract['metric_key'] => 'Debe cumplirse Observacion < Desviacion < Critico.',
            ]);
        }

        return [
            'observation_threshold' => $observation,
            'deviation_threshold' => $deviation,
            'critical_threshold' => $critical,
            'minimum_baseline' => $contract['comparison_mode'] === 'relative_percent'
                ? $this->decimal($submitted['minimum_baseline'], 'Baseline minimo', true)
                : null,
            'minimum_absolute_change' => $contract['comparison_mode'] === 'relative_percent'
                ? $this->decimal($submitted['minimum_absolute_change'], 'Cambio absoluto minimo', true)
                : null,
        ];
    }

    private function decimal(mixed $value, string $label, bool $allowZero, int|float $maximum = 9999999999999999): string
    {
        $raw = is_string($value) || is_int($value) ? (string) $value : '';
        if (! preg_match('/^\d{1,16}(?:\.\d{1,8})?$/D', $raw)
            || (! $allowZero && (float) $raw <= 0)
            || (float) $raw > $maximum) {
            throw ValidationException::withMessages([
                'rules' => "{$label} contiene un valor no valido.",
            ]);
        }

        return $raw;
    }
}
