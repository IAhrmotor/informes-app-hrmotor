<?php

namespace App\Services\SeoAnalytics;

use App\Models\AnalyticalRuleSet;
use App\Services\Analytics\AnalyticalEvaluationEngine;
use RuntimeException;

final class SeoAnalyticalRuleSetResolver
{
    public function __construct(private readonly SeoAnalyticalMetricRegistry $registry) {}

    public function active(): AnalyticalRuleSet
    {
        $ruleSets = AnalyticalRuleSet::query()
            ->where('module_key', SeoAnalyticalMetricRegistry::MODULE)
            ->where('status', 'active')
            ->with('rules')
            ->orderByDesc('version_number')
            ->get();

        if ($ruleSets->count() !== 1) {
            throw new RuntimeException('Debe existir exactamente un rule set SEO activo.');
        }

        $ruleSet = $ruleSets->firstOrFail();
        $this->assertComplete($ruleSet);

        return $ruleSet;
    }

    public function assertComplete(AnalyticalRuleSet $ruleSet): void
    {
        $ruleSet->loadMissing('rules');
        $expected = collect($this->registry->metrics())->pluck('key')->sort()->values()->all();
        $actual = $ruleSet->rules->pluck('metric_key')->sort()->values()->all();
        if ($actual !== $expected) {
            throw new RuntimeException('El rule set SEO activo no contiene las seis reglas requeridas.');
        }

        foreach ($ruleSet->rules as $rule) {
            if (! in_array($rule->comparison_mode, AnalyticalEvaluationEngine::COMPARISON_MODES, true)
                || ! in_array($rule->favorable_direction, AnalyticalEvaluationEngine::FAVORABLE_DIRECTIONS, true)
                || ! in_array($rule->threshold_unit, ['percent', 'percentage_points', 'positions'], true)
                || (float) $rule->observation_threshold <= 0
                || (float) $rule->deviation_threshold <= (float) $rule->observation_threshold
                || (float) $rule->critical_threshold <= (float) $rule->deviation_threshold) {
                throw new RuntimeException('El rule set SEO activo contiene una regla invalida.');
            }

            if ($rule->comparison_mode === 'relative_percent'
                && ($rule->minimum_baseline === null || $rule->minimum_absolute_change === null)) {
                throw new RuntimeException('Una regla SEO de volumen no contiene materialidad completa.');
            }
        }
    }
}
