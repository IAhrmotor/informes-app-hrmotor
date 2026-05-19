<?php

namespace App\Console\Commands;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ReprocessOpportunityPortalsCommand extends Command
{
    protected $signature = 'reports:reprocess-opportunity-portals
        {--limit= : Limita el numero de oportunidades a reprocesar}
        {--fresh-cache-clear : Limpia toda la cache de Laravel al terminar}';

    protected $description = 'Recalcula portal_resolved de Opportunities con la normalizacion de Reservas / Ventas.';

    public function handle(SalesforceOpportunitySyncService $sync, OpportunityPortalNormalizer $normalizer): int
    {
        $limit = $this->option('limit') !== null ? max((int) $this->option('limit'), 1) : null;
        $processed = 0;
        $stats = [
            'opportunity' => 0,
            'lead' => 0,
            'opportunity_source' => 0,
            'fallback_exposicion' => 0,
            'fallback_web' => 0,
            'unclassified' => 0,
            'errors' => 0,
        ];

        SalesforceOpportunity::query()
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$processed, $limit, &$stats, $sync, $normalizer): bool {
                if ($limit !== null) {
                    $rows = $rows->take(max($limit - $processed, 0));
                }

                if ($rows->isEmpty()) {
                    return false;
                }

                $records = $rows->map(fn (SalesforceOpportunity $opportunity) => $this->opportunityRecord($opportunity))->all();
                $leadMatches = $sync->relatedLeadMatchesForOpportunities($records);

                foreach ($rows as $opportunity) {
                    try {
                        $record = $this->opportunityRecord($opportunity);
                        $portal = $sync->resolvePortalForRecord($record, $leadMatches);
                        $source = $normalizer->normalize(data_get($record, 'Fuente_de_Origen__c'));

                        $opportunity->forceFill([
                            'opportunity_source_raw' => data_get($record, 'Fuente_de_Origen__c'),
                            'opportunity_source_normalized' => $source['portal'],
                            'portal_resolved' => $portal['portal'],
                            'portal_resolution_source' => $portal['source'],
                            'portal_resolution_lead_id' => $portal['lead_id'],
                            'portal_resolution_debug' => $portal['debug'],
                        ])->save();

                        $stats[$portal['source']]++;
                    } catch (Throwable) {
                        $stats['errors']++;
                    }

                    $processed++;

                    if ($limit !== null && $processed >= $limit) {
                        break;
                    }
                }

                return $limit === null || $processed < $limit;
            });

        if ($this->option('fresh-cache-clear')) {
            Cache::clear();
            $this->line('Cache completa limpiada.');
        } else {
            Cache::forever('reservas_ventas_dashboard_cache_version', ((int) Cache::get('reservas_ventas_dashboard_cache_version', 1)) + 1);
            $this->line('Cache del dashboard Reservas / Ventas invalidada.');
        }

        $this->info('Reproceso de portales completado.');
        $this->line('Oportunidades procesadas: '.$processed);
        $this->line('Resueltas por opportunity: '.$stats['opportunity']);
        $this->line('Resueltas por lead: '.$stats['lead']);
        $this->line('Resueltas por opportunity_source: '.$stats['opportunity_source']);
        $this->line('Fallback Exposicion: '.$stats['fallback_exposicion']);
        $this->line('Fallback Web: '.$stats['fallback_web']);
        $this->line('Sin clasificar: '.$stats['unclassified']);
        $this->line('Errores: '.$stats['errors']);

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function opportunityRecord(SalesforceOpportunity $opportunity): array
    {
        return [
            'Id' => $opportunity->salesforce_id,
            'Portal__c' => $opportunity->portal_original,
            'Fuente_de_Origen__c' => $opportunity->opportunity_source_raw ?: data_get($opportunity->raw_payload, 'Fuente_de_Origen__c'),
            'Account' => [
                'Phone' => $opportunity->account_phone,
                'PersonEmail' => $opportunity->account_person_email,
                'AC_C_EMA_email__c' => $opportunity->account_company_email,
            ],
        ];
    }
}
