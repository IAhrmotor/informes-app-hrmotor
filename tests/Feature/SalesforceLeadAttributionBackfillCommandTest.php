<?php

namespace Tests\Feature;

use App\Models\CampaignSalesforceLead;
use App\Models\SalesforceLead;
use App\Models\SalesforceLeadAttributionBackfillHistory;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceLeadAttributionBackfillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesforceLeadAttributionBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_an_explicit_exclusive_mode_range_and_apply_reason(): void
    {
        $fake = $this->fakeSalesforce(fn (): array => []);

        $this->artisan('salesforce:backfill-lead-attribution-fields', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
        ])->assertFailed();
        $this->artisan('salesforce:backfill-lead-attribution-fields', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
            '--dry-run' => true,
            '--apply' => true,
        ])->assertFailed();
        $this->artisan('salesforce:backfill-lead-attribution-fields', [
            '--from' => '2026-02-01',
            '--to' => '2026-01-01',
            '--dry-run' => true,
        ])->assertFailed();
        $this->artisan('salesforce:backfill-lead-attribution-fields', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
            '--apply' => true,
            '--reason' => 'corto',
        ])->assertFailed();

        $this->assertSame([], $fake->queries);
        $this->assertDatabaseCount('salesforce_lead_attribution_backfill_history', 0);
    }

    public function test_dry_run_is_non_persistent_and_reports_reconciliation_without_inserting_extra_ids(): void
    {
        $id = $this->leadId(1);
        $utmOnlyId = $this->leadId(4);
        $extraId = $this->leadId(99);
        $this->generalLead($id, [
            'fuente_origen' => 'Coches.net',
            'medio_origen' => 'Legacy medium',
            'medio_nuevo' => 'Llamada',
            'delegacion_encargada_bueno' => 'Valencia',
            'campaign_acquired' => null,
            'acquired_id' => null,
            'content_acquired' => null,
            'raw_payload' => ['unrelated_key' => 'preserved'],
        ]);
        $this->campaignLead($id, ['fuente_origen' => 'Coches.net']);
        $this->generalLead($utmOnlyId, [
            'fuente_origen' => 'none',
            'medio_origen' => 'none',
        ]);
        Cache::forever('lead_dashboard_cache_version', 11);
        Cache::forever('campaign_dashboard_cache_version', 13);

        $fake = $this->fakeSalesforce(fn (): array => [
            $this->salesforceRecord($id, [
                'Fuente_origen__c' => 'Sin clasificar',
                'Medio_origen__c' => ' ',
                'Canal__c' => null,
                'Delegacion_procedencia__c' => 'Madrid',
                'utm_campaign__c' => 'New campaign',
            ]),
            $this->salesforceRecord($utmOnlyId, ['utm_source__c' => 'Google']),
            $this->salesforceRecord($extraId, ['Fuente_origen__c' => 'Never insert']),
        ]);

        $this->artisan('salesforce:backfill-lead-attribution-fields', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
            '--dry-run' => true,
            '--debug-soql' => true,
        ])
            ->assertSuccessful();

        $stats = $this->app->make(SalesforceLeadAttributionBackfillService::class)->run(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-02-01'),
            false,
        );
        $this->assertSame(1, $stats['utm_only_detected']);

        $lead = SalesforceLead::query()->where('salesforce_id', $id)->sole();
        $this->assertNull($lead->source_origin_new);
        $this->assertSame(['unrelated_key' => 'preserved'], $lead->raw_payload);
        $this->assertDatabaseCount('salesforce_lead_attribution_backfill_history', 0);
        $this->assertDatabaseMissing('salesforce_leads', ['salesforce_id' => $extraId]);
        $this->assertDatabaseMissing('campaign_salesforce_leads', ['salesforce_id' => $extraId]);
        $this->assertDatabaseMissing('campaign_salesforce_leads', ['salesforce_id' => $utmOnlyId]);
        $this->assertSame(11, Cache::get('lead_dashboard_cache_version'));
        $this->assertSame(13, Cache::get('campaign_dashboard_cache_version'));
        $this->assertCount(2, $fake->queries);
        $this->assertStringNotContainsString('Name', $fake->queries[0]);
        $this->assertStringNotContainsString('Email', $fake->queries[0]);
        $this->assertStringNotContainsString('Phone', $fake->queries[0]);
    }

    public function test_apply_updates_only_approved_fields_audits_both_tables_and_is_idempotent(): void
    {
        $id = $this->leadId(2);
        $this->generalLead($id, [
            'name' => 'Synthetic untouched',
            'fuente_origen' => 'Coches.net',
            'medio_origen' => 'Legacy medium',
            'medio_nuevo' => 'Llamada',
            'portal_text' => 'Legacy portal',
            'delegacion_encargada_bueno' => 'Valencia',
            'campaign_acquired' => 'Legacy campaign',
            'acquired_id' => 'legacy-id',
            'content_acquired' => 'legacy-content',
            'raw_payload' => [
                'unrelated_key' => 'preserved',
                'Fuente_origen__c' => 'Old raw',
            ],
        ]);
        $this->campaignLead($id, [
            'name' => 'Synthetic campaign untouched',
            'fuente_origen' => 'Coches.net',
            'medio_origen' => 'Legacy medium',
            'campaign_acquired' => 'Legacy campaign',
            'acquired_id' => 'legacy-id',
            'content_acquired' => 'legacy-content',
            'raw_payload' => ['unrelated_key' => 'campaign-preserved'],
        ]);
        $record = $this->salesforceRecord($id, [
            'Fuente_origen__c' => 'Sin clasificar',
            'Medio_origen__c' => '',
            'Canal__c' => 'WhatsApp',
            'Delegacion_procedencia__c' => 'Madrid',
            'Fuente_Adquirida__c' => 'New legacy source snapshot',
            'Medio_Adquirido__c' => 'New legacy medium snapshot',
            'utm_campaign__c' => 'New campaign',
            'utm_id__c' => 'new-id',
            'utm_source__c' => 'Sin informar',
            'utm_medium__c' => 'new-medium',
            'utm_content__c' => 'new-content',
        ]);
        $this->fakeSalesforce(fn (): array => [$record]);
        Cache::forever('lead_dashboard_cache_version', 1);
        Cache::forever('campaign_dashboard_cache_version', 1);

        $arguments = [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
            '--apply' => true,
            '--reason' => 'Backfill controlado de pruebas',
        ];
        $this->artisan('salesforce:backfill-lead-attribution-fields', $arguments)
            ->expectsOutputToContain('"mode":"apply"')
            ->assertSuccessful();

        $lead = SalesforceLead::query()->where('salesforce_id', $id)->sole();
        $campaignLead = CampaignSalesforceLead::query()->where('salesforce_id', $id)->sole();
        $this->assertSame('Synthetic untouched', $lead->name);
        $this->assertSame('Coches.net', $lead->fuente_origen);
        $this->assertSame('Legacy campaign', $lead->campaign_acquired);
        $this->assertSame('Sin clasificar', $lead->source_origin_new);
        $this->assertSame('', $lead->medium_origin_new);
        $this->assertSame('WhatsApp', $lead->resolved_channel);
        $this->assertSame('Sin clasificar', $lead->resolved_portal);
        $this->assertSame('Fuente_origen__c', $lead->portal_resolution_source);
        $this->assertSame('Fuente_origen__c', data_get($lead->field_resolution, 'source.source_field'));
        $this->assertSame('LEA_SEL_Medio_Origen__c', data_get($lead->field_resolution, 'medium.source_field'));
        $this->assertSame('Sin informar', data_get($lead->field_resolution, 'utm_source.effective_value'));
        $this->assertSame('preserved', data_get($lead->raw_payload, 'unrelated_key'));
        $this->assertSame('Sin clasificar', data_get($lead->raw_payload, 'Fuente_origen__c'));
        $this->assertSame('Synthetic campaign untouched', $campaignLead->name);
        $this->assertSame('Sin clasificar', $campaignLead->source_origin_new);
        $this->assertSame('campaign-preserved', data_get($campaignLead->raw_payload, 'unrelated_key'));
        $this->assertDatabaseCount('salesforce_lead_attribution_backfill_history', 2);
        $this->assertSame(2, Cache::get('lead_dashboard_cache_version'));
        $this->assertSame(2, Cache::get('campaign_dashboard_cache_version'));

        $history = SalesforceLeadAttributionBackfillHistory::query()
            ->where('source_table', 'salesforce_leads')
            ->sole();
        $this->assertSame('Backfill controlado de pruebas', $history->reason);
        $this->assertContains('source_origin_new', $history->changed_fields);
        $this->assertContains('raw_payload', $history->changed_fields);
        $this->assertArrayNotHasKey('unrelated_key', $history->previous_values['raw_payload']);
        $this->assertArrayNotHasKey('unrelated_key', $history->new_values['raw_payload']);

        $historyCount = SalesforceLeadAttributionBackfillHistory::query()->count();
        $this->artisan('salesforce:backfill-lead-attribution-fields', $arguments)
            ->expectsOutputToContain('"salesforce_leads":0')
            ->assertSuccessful();
        $this->assertSame($historyCount, SalesforceLeadAttributionBackfillHistory::query()->count());
        $this->assertSame(2, Cache::get('lead_dashboard_cache_version'));
        $this->assertSame(2, Cache::get('campaign_dashboard_cache_version'));
    }

    public function test_limit_and_resume_cursor_process_a_deterministic_subset(): void
    {
        $firstId = $this->leadId(10);
        $secondId = $this->leadId(11);
        $thirdId = $this->leadId(12);
        foreach ([$firstId, $secondId, $thirdId] as $id) {
            $this->generalLead($id);
        }
        $this->fakeSalesforce(function (string $soql) use ($firstId, $secondId, $thirdId): array {
            return collect([$firstId, $secondId, $thirdId])
                ->filter(fn (string $id): bool => str_contains($soql, $id))
                ->map(fn (string $id): array => $this->salesforceRecord($id, ['Fuente_origen__c' => 'Source '.$id]))
                ->all();
        });

        $stats = $this->app->make(SalesforceLeadAttributionBackfillService::class)->run(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-02-01'),
            false,
            limit: 1,
            afterSalesforceId: $firstId,
        );

        $this->assertSame(1, $stats['salesforce_ids_unique']);
        $this->assertSame($secondId, $stats['last_salesforce_id_processed']);
    }

    public function test_missing_salesforce_id_is_not_cleared_deleted_or_modified(): void
    {
        $id = $this->leadId(20);
        $this->generalLead($id, [
            'source_origin_new' => 'Existing source',
            'is_deleted' => false,
        ]);
        $this->fakeSalesforce(fn (): array => []);

        $this->artisan('salesforce:backfill-lead-attribution-fields', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
            '--apply' => true,
            '--reason' => 'Missing Salesforce reconciliation',
        ])
            ->expectsOutputToContain('"ids_not_found_in_salesforce":1')
            ->assertSuccessful();

        $lead = SalesforceLead::query()->where('salesforce_id', $id)->sole();
        $this->assertSame('Existing source', $lead->source_origin_new);
        $this->assertFalse($lead->is_deleted);
        $this->assertDatabaseCount('salesforce_lead_attribution_backfill_history', 0);
    }

    public function test_campaign_only_local_lead_is_updated_without_inserting_general_lead(): void
    {
        $id = $this->leadId(30);
        $this->campaignLead($id);
        $this->fakeSalesforce(fn (): array => [
            $this->salesforceRecord($id, ['utm_content__c' => 'Campaign-only content']),
        ]);

        $this->artisan('salesforce:backfill-lead-attribution-fields', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
            '--apply' => true,
            '--reason' => 'Campaign-only existing row',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('salesforce_leads', ['salesforce_id' => $id]);
        $this->assertSame(
            'Campaign-only content',
            CampaignSalesforceLead::query()->where('salesforce_id', $id)->value('utm_content_new'),
        );
        $this->assertDatabaseHas('salesforce_lead_attribution_backfill_history', [
            'source_table' => 'campaign_salesforce_leads',
            'salesforce_id' => $id,
        ]);
    }

    public function test_invalid_local_id_is_never_interpolated_into_soql(): void
    {
        $this->generalLead('not-a-salesforce-id');
        $fake = $this->fakeSalesforce(fn (): array => []);

        $stats = $this->app->make(SalesforceLeadAttributionBackfillService::class)->run(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-02-01'),
            false,
        );

        $this->assertSame([], $fake->queries);
        $this->assertSame(1, $stats['ids_invalid_local']);
        $this->assertSame(0, $stats['ids_consulted']);
        $this->assertSame('not-a-salesforce-id', $stats['last_salesforce_id_processed']);
    }

    public function test_salesforce_failure_on_later_chunk_does_not_write_the_affected_chunk(): void
    {
        foreach (range(1, 101) as $number) {
            $this->generalLead($this->leadId($number));
        }
        $queryNumber = 0;
        $this->fakeSalesforce(function (string $soql) use (&$queryNumber): array {
            $queryNumber++;

            if ($queryNumber === 2) {
                throw new \RuntimeException('Synthetic Salesforce failure');
            }

            preg_match_all('/00Q[A-Za-z0-9]{12}(?:[A-Za-z0-9]{3})?/', $soql, $matches);

            return collect($matches[0])
                ->map(fn (string $id): array => $this->salesforceRecord($id, ['Fuente_origen__c' => 'First chunk']))
                ->all();
        });

        $this->artisan('salesforce:backfill-lead-attribution-fields', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-01',
            '--apply' => true,
            '--reason' => 'Failure boundary regression',
        ])->assertFailed();

        $this->assertSame(100, SalesforceLead::query()->where('source_origin_new', 'First chunk')->count());
        $this->assertNull(SalesforceLead::query()->where('salesforce_id', $this->leadId(101))->value('source_origin_new'));
        $this->assertSame(100, SalesforceLeadAttributionBackfillHistory::query()->count());
    }

    public function test_database_failure_rolls_back_updates_and_history_for_the_whole_chunk(): void
    {
        $id = $this->leadId(200);
        $this->generalLead($id);
        $this->campaignLead($id);
        $this->fakeSalesforce(fn (): array => [
            $this->salesforceRecord($id, ['Fuente_origen__c' => 'Rollback source']),
        ]);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_campaign_backfill
BEFORE UPDATE ON campaign_salesforce_leads
BEGIN
    SELECT RAISE(FAIL, 'synthetic campaign update failure');
END
SQL);

        try {
            $this->artisan('salesforce:backfill-lead-attribution-fields', [
                '--from' => '2026-01-01',
                '--to' => '2026-02-01',
                '--apply' => true,
                '--reason' => 'Database rollback regression',
            ])->assertFailed();
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_campaign_backfill');
        }

        $this->assertNull(SalesforceLead::query()->where('salesforce_id', $id)->value('source_origin_new'));
        $this->assertNull(CampaignSalesforceLead::query()->where('salesforce_id', $id)->value('source_origin_new'));
        $this->assertDatabaseCount('salesforce_lead_attribution_backfill_history', 0);
    }

    private function generalLead(string $id, array $overrides = []): SalesforceLead
    {
        return SalesforceLead::query()->create(array_merge([
            'salesforce_id' => $id,
            'created_date' => '2026-01-15 10:00:00',
        ], $overrides));
    }

    private function campaignLead(string $id, array $overrides = []): CampaignSalesforceLead
    {
        return CampaignSalesforceLead::query()->create(array_merge([
            'salesforce_id' => $id,
            'created_date' => '2026-01-15 10:00:00',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function salesforceRecord(string $id, array $overrides = []): array
    {
        return array_merge([
            'Id' => $id,
            'Fuente_origen__c' => null,
            'Medio_origen__c' => null,
            'Canal__c' => null,
            'Delegacion_procedencia__c' => null,
            'Fuente_Adquirida__c' => null,
            'Medio_Adquirido__c' => null,
            'utm_campaign__c' => null,
            'utm_id__c' => null,
            'utm_source__c' => null,
            'utm_medium__c' => null,
            'utm_content__c' => null,
        ], $overrides);
    }

    private function leadId(int $number): string
    {
        return '00Q'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);
    }

    private function fakeSalesforce(callable $handler): SalesforceClient
    {
        $fake = new class($handler) extends SalesforceClient
        {
            /** @var list<string> */
            public array $queries = [];

            public function __construct(private $handler) {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return ($this->handler)($soql);
            }
        };

        $this->app->instance(SalesforceClient::class, $fake);
        $this->app->forgetInstance(SalesforceLeadAttributionBackfillService::class);

        return $fake;
    }
}
