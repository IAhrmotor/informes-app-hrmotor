<?php

namespace App\Services\Campaigns;

class CampaignPerformanceClassifier
{
    private const LOW_SPEND_THRESHOLD = 50.0;
    private const RELEVANT_SPEND_THRESHOLD = 150.0;
    private const HIGH_SPEND_THRESHOLD = 500.0;
    private const MIN_LEADS_TO_REVIEW = 5;

    public function classify(array $row, array $benchmarks): string
    {
        $spend = (float) ($row['spend'] ?? 0);
        $leads = (int) ($row['leads_salesforce'] ?? 0);
        $sales = (int) ($row['sales'] ?? 0);
        $reservations = (int) ($row['reservations'] ?? 0);
        $costPerSale = $row['cost_per_sale'] ?? null;
        $roas = $row['roas'] ?? null;

        if ($spend > 0 && $leads === 0) {
            return 'Revisar tracking';
        }

        if ($leads > 0 && $spend <= 0.0) {
            return 'Revisar inversión/tracking';
        }

        if ($spend < self::LOW_SPEND_THRESHOLD && $leads < self::MIN_LEADS_TO_REVIEW) {
            return 'Sin datos suficientes';
        }

        if ($sales > 0
            && $costPerSale !== null
            && $benchmarks['avg_cost_per_sale'] !== null
            && $roas !== null
            && $benchmarks['avg_roas'] !== null
            && $costPerSale <= $benchmarks['avg_cost_per_sale']
            && $roas >= $benchmarks['avg_roas']) {
            return 'Potenciar';
        }

        if ($spend >= self::HIGH_SPEND_THRESHOLD && $sales === 0 && $reservations === 0) {
            return 'Parar';
        }

        if ($spend >= self::RELEVANT_SPEND_THRESHOLD && ($sales === 0 || $leads >= self::MIN_LEADS_TO_REVIEW)) {
            return 'Revisar';
        }

        return 'Sin datos suficientes';
    }
}
