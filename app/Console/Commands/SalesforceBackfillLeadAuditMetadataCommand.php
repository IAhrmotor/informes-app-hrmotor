<?php

namespace App\Console\Commands;

use App\Models\SalesforceLead;
use App\Services\Reports\Leads\LeadClassificationResolver;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use Illuminate\Console\Command;

class SalesforceBackfillLeadAuditMetadataCommand extends Command
{
    protected $signature = 'salesforce:backfill-lead-audit-metadata {--chunk=500} {--dry-run}';

    protected $description = 'Completa metadatos auditables ausentes usando la ultima copia local disponible';

    public function handle(LeadRecordTypeNormalizer $types, LeadClassificationResolver $classifications): int
    {
        $chunk = max(50, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $changed = 0;

        SalesforceLead::query()
            ->where(function ($query): void {
                $query->whereNull('record_type_normalized')
                    ->orWhereNull('resolved_portal')
                    ->orWhereNull('field_resolution')
                    ->orWhereNull('synced_at')
                    ->orWhereNull('sync_metadata_source');
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($types, $classifications, $dryRun, &$processed, &$changed): void {
                foreach ($rows as $row) {
                    $processed++;
                    $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
                    $resolution = $classifications->resolve($row);
                    $updates = [
                        'record_type_normalized' => $types->normalize($row->record_type_name),
                        'resolved_channel' => $resolution['channel'],
                        'resolved_portal' => $resolution['portal'],
                        'portal_resolution_source' => $resolution['portal_resolution_source'],
                        'field_resolution' => $resolution['fields'],
                        'salesforce_last_modified_at' => $row->salesforce_last_modified_at ?: data_get($payload, 'LastModifiedDate'),
                        'salesforce_master_record_id' => $row->salesforce_master_record_id ?: data_get($payload, 'MasterRecordId'),
                        'synced_at' => $row->synced_at ?: $row->updated_at,
                        'sync_metadata_source' => $row->sync_metadata_source ?: 'legacy_local_backfill',
                    ];

                    $row->forceFill($updates);
                    if ($row->isDirty()) {
                        $changed++;
                    }

                    if (! $dryRun) {
                        $row->save();
                    }
                }
            });

        $this->info("Procesados: {$processed}; actualizables: {$changed}; dry-run: ".($dryRun ? 'si' : 'no'));
        $this->warn('Los valores con origen legacy_local_backfill no demuestran un corte Salesforce. Para historicos fiables usa salesforce:sync-monthly-commercial --from=YYYY-MM-DD --to=YYYY-MM-DD.');

        return self::SUCCESS;
    }
}
