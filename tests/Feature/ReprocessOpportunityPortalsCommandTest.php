<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityPortalReprocessHistory;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Reports\ReservationsSales\OpportunityPortalReprocessService;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use App\Services\Salesforce\SalesforceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReprocessOpportunityPortalsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-05-01';

    private const TO = '2026-06-01';

    public function test_requires_explicit_mode_range_reason_limit_and_cursor(): void
    {
        $fake = $this->fakeSalesforce(fn (): array => []);

        $this->artisan('reports:reprocess-opportunity-portals', ['--from' => self::FROM, '--to' => self::TO])->assertFailed();
        $this->artisan('reports:reprocess-opportunity-portals', [
            '--from' => self::FROM,
            '--to' => self::TO,
            '--dry-run' => true,
            '--apply' => true,
        ])->assertFailed();
        $this->artisan('reports:reprocess-opportunity-portals', [
            '--from' => self::TO,
            '--to' => self::FROM,
            '--dry-run' => true,
        ])->assertFailed();
        $this->artisan('reports:reprocess-opportunity-portals', [
            '--from' => self::FROM,
            '--to' => self::TO,
            '--apply' => true,
            '--reason' => 'corto',
        ])->assertFailed();
        $this->artisan('reports:reprocess-opportunity-portals', [
            '--from' => self::FROM,
            '--to' => self::TO,
            '--dry-run' => true,
            '--limit' => 'invalid',
        ])->assertFailed();
        $this->artisan('reports:reprocess-opportunity-portals', [
            '--from' => self::FROM,
            '--to' => self::TO,
            '--dry-run' => true,
            '--after-id' => '0',
        ])->assertFailed();

        $this->assertSame([], $fake->queries);
        $this->assertDatabaseCount('salesforce_opportunity_portal_reprocess_history', 0);
    }

    public function test_dry_run_reports_change_without_updating_history_timestamp_or_cache(): void
    {
        $this->travelTo('2026-09-03 09:00:00');
        $opportunity = $this->opportunity([
            'portal_original' => 'COCHES.NET',
            'portal_resolved' => 'Legacy value',
            'portal_resolution_source' => 'unclassified',
        ]);
        $beforeUpdatedAt = $opportunity->updated_at;
        Cache::forever('reservas_ventas_dashboard_cache_version', 4);
        $fake = $this->fakeSalesforce(fn (): array => []);

        $this->artisan('reports:reprocess-opportunity-portals', [
            '--from' => self::FROM,
            '--to' => self::TO,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('REPROCESS_METRICS=')
            ->assertSuccessful();

        $stats = $this->service()->run(
            now()->parse(self::FROM)->toImmutable(),
            now()->parse(self::TO)->toImmutable(),
            false,
        );

        $opportunity->refresh();
        $this->assertSame(1, $stats['rows_changed']);
        $this->assertSame('Legacy value', $opportunity->portal_resolved);
        $this->assertTrue($beforeUpdatedAt->equalTo($opportunity->updated_at));
        $this->assertDatabaseCount('salesforce_opportunity_portal_reprocess_history', 0);
        $this->assertSame(4, Cache::get('reservas_ventas_dashboard_cache_version'));
        $this->assertNotEmpty($fake->queries);
        $this->assertTrue(collect($fake->queries)->every(fn (string $query): bool => str_contains($query, 'FROM Lead')));
        $this->assertSame(0, $fake->createCalls);
        $this->assertSame(0, $fake->updateCalls);
    }

    public function test_apply_updates_only_attribution_fields_audits_safely_and_is_idempotent(): void
    {
        $this->travelTo('2026-09-03 09:00:00');
        $rawPayload = ['Fuente_de_Origen__c' => 'Google', 'unrelated' => 'preserved'];
        $opportunity = $this->opportunity([
            'name' => 'Synthetic opportunity',
            'portal_original' => '3CX',
            'portal_resolved' => 'Old portal',
            'portal_resolution_source' => 'unclassified',
            'portal_resolution_debug' => [
                'reason' => 'old',
                'account_phone' => '600000001',
                'email' => 'synthetic@example.test',
                'unknown_future_key' => 'private',
            ],
            'raw_payload' => $rawPayload,
            'reservation' => true,
            'cv_signed' => true,
            'amount' => 12345.67,
        ]);
        $fake = $this->fakeSalesforce(fn (): array => [$this->leadRecord()]);
        Cache::forever('reservas_ventas_dashboard_cache_version', 1);
        $arguments = $this->applyArguments();

        $this->travelTo('2026-09-03 10:00:00');
        $this->artisan('reports:reprocess-opportunity-portals', $arguments)
            ->expectsOutputToContain('"rows_changed":1')
            ->assertSuccessful();

        $opportunity->refresh();
        $firstUpdatedAt = $opportunity->updated_at;
        $this->assertSame('Coches.net', $opportunity->portal_resolved);
        $this->assertSame('lead', $opportunity->portal_resolution_source);
        $this->assertSame('00Qsynthetic001', $opportunity->portal_resolution_lead_id);
        $this->assertSame('Google', $opportunity->opportunity_source_raw);
        $this->assertSame('Web', $opportunity->opportunity_source_normalized);
        $this->assertSame('Synthetic opportunity', $opportunity->name);
        $this->assertSame('600000001', $opportunity->account_phone);
        $this->assertSame('synthetic@example.test', $opportunity->account_person_email);
        $this->assertSame($rawPayload, $opportunity->raw_payload);
        $this->assertTrue($opportunity->reservation);
        $this->assertTrue($opportunity->cv_signed);
        $this->assertSame('12345.67', $opportunity->amount);
        $this->assertSame(2, Cache::get('reservas_ventas_dashboard_cache_version'));
        $this->assertDatabaseCount('salesforce_opportunity_portal_reprocess_history', 1);

        $history = SalesforceOpportunityPortalReprocessHistory::query()->sole();
        $historyJson = json_encode($history->toArray(), JSON_THROW_ON_ERROR);
        $this->assertContains('portal_resolution_debug', $history->changed_fields);
        $this->assertArrayNotHasKey('account_phone', $history->previous_values['portal_resolution_debug']);
        $this->assertArrayNotHasKey('email', $history->previous_values['portal_resolution_debug']);
        $this->assertArrayNotHasKey('unknown_future_key', $history->previous_values['portal_resolution_debug']);
        $this->assertStringNotContainsString('synthetic@example.test', $historyJson);
        $this->assertStringNotContainsString('600000001', $historyJson);

        $this->travelTo('2026-09-03 11:00:00');
        $this->artisan('reports:reprocess-opportunity-portals', $arguments)
            ->expectsOutputToContain('"rows_changed":0')
            ->assertSuccessful();

        $opportunity->refresh();
        $this->assertTrue($firstUpdatedAt->equalTo($opportunity->updated_at));
        $this->assertDatabaseCount('salesforce_opportunity_portal_reprocess_history', 1);
        $this->assertSame(2, Cache::get('reservas_ventas_dashboard_cache_version'));
        $this->assertNotEmpty($fake->queries);
        foreach ($fake->queries as $soql) {
            $this->assertStringContainsString('FROM Lead', $soql);
            $this->assertStringNotContainsString('FROM Opportunity', $soql);
        }
        $this->assertSame(0, $fake->createCalls);
        $this->assertSame(0, $fake->updateCalls);
    }

    public function test_range_limit_and_resume_use_local_ids_deterministically(): void
    {
        $outside = $this->opportunity(['created_date' => '2026-06-01 00:00:00', 'portal_original' => 'COCHES.NET']);
        $first = $this->opportunity(['salesforce_id' => '006-first', 'portal_original' => 'COCHES.NET']);
        $second = $this->opportunity(['salesforce_id' => '006-second', 'portal_original' => 'WALLAPOP']);
        $this->fakeSalesforce(fn (): array => []);

        $stats = $this->service()->run(
            now()->parse(self::FROM)->toImmutable(),
            now()->parse(self::TO)->toImmutable(),
            false,
            limit: 1,
            afterId: $first->id,
        );

        $this->assertSame(1, $stats['rows_examined']);
        $this->assertSame($second->id, $stats['last_local_id_processed']);
        $this->assertSame('006-second', $stats['last_salesforce_id_processed']);
        $this->assertNotSame($outside->id, $stats['last_local_id_processed']);
    }

    public function test_phase_five_precedence_remains_available_through_the_reprocess_service(): void
    {
        $newSource = $this->opportunity(['salesforce_id' => '006-new', 'portal_original' => '3CX']);
        $conclusive = $this->opportunity(['salesforce_id' => '006-direct', 'portal_original' => 'WALLAPOP']);
        $this->fakeSalesforce(fn (): array => [$this->leadRecord([
            'Fuente_origen__c' => 'Sin clasificar',
            'Portal_Text__c' => 'Google Maps',
        ])]);

        $stats = $this->service()->run(
            now()->parse(self::FROM)->toImmutable(),
            now()->parse(self::TO)->toImmutable(),
            false,
        );

        $this->assertSame(2, $stats['rows_changed']);
        $this->assertSame(1, $stats['resolution_after']['lead']);
        $this->assertSame(1, $stats['resolution_after']['opportunity']);
        $this->assertSame(1, $stats['selected_lead_new_source']);
        $this->assertSame(0, $stats['selected_lead_legacy_fallback']);
        $this->assertNull($newSource->fresh()->portal_resolution_source);
        $this->assertNull($conclusive->fresh()->portal_resolution_source);
    }

    public function test_stale_snapshot_requeries_leads_and_builds_history_from_locked_state(): void
    {
        $opportunity = $this->opportunity([
            'portal_original' => '3CX',
            'portal_resolved' => 'Old portal',
            'portal_resolution_source' => 'unclassified',
        ]);
        $fake = $this->fakeSalesforce(fn (): array => [$this->leadRecord()]);
        $service = new class($this->app->make(SalesforceOpportunitySyncService::class), $this->app->make(OpportunityPortalNormalizer::class), $opportunity->id) extends OpportunityPortalReprocessService
        {
            public function __construct(
                SalesforceOpportunitySyncService $sync,
                OpportunityPortalNormalizer $normalizer,
                private readonly int $opportunityId,
            ) {
                parent::__construct($sync, $normalizer);
            }

            protected function beforeApplyTransaction(array $ids, int $attempt): void
            {
                if ($attempt === 1) {
                    DB::table('salesforce_opportunities')
                        ->where('id', $this->opportunityId)
                        ->update([
                            'portal_original' => 'WALLAPOP',
                            'portal_resolved' => 'Concurrent previous portal',
                        ]);
                }
            }
        };

        $stats = $service->run(
            now()->parse(self::FROM)->toImmutable(),
            now()->parse(self::TO)->toImmutable(),
            true,
            'Stale snapshot retry regression',
        );

        $opportunity->refresh();
        $history = SalesforceOpportunityPortalReprocessHistory::query()->sole();
        $this->assertFalse($stats['failed']);
        $this->assertSame(1, $stats['concurrency_retries']);
        $this->assertCount(4, $fake->queries);
        $this->assertSame('WALLAPOP', $opportunity->portal_original);
        $this->assertSame('Wallapop', $opportunity->portal_resolved);
        $this->assertSame('opportunity', $opportunity->portal_resolution_source);
        $this->assertSame('Concurrent previous portal', $history->previous_values['portal_resolved']);
    }

    public function test_persistent_concurrent_changes_fail_without_writes_or_cursor_advance(): void
    {
        $opportunity = $this->opportunity(['portal_original' => '3CX', 'portal_resolved' => 'Old portal']);
        $this->fakeSalesforce(fn (): array => [$this->leadRecord()]);
        Cache::forever('reservas_ventas_dashboard_cache_version', 8);
        $service = new class($this->app->make(SalesforceOpportunitySyncService::class), $this->app->make(OpportunityPortalNormalizer::class), $opportunity->id) extends OpportunityPortalReprocessService
        {
            public function __construct(
                SalesforceOpportunitySyncService $sync,
                OpportunityPortalNormalizer $normalizer,
                private readonly int $opportunityId,
            ) {
                parent::__construct($sync, $normalizer);
            }

            protected function beforeApplyTransaction(array $ids, int $attempt): void
            {
                DB::table('salesforce_opportunities')
                    ->where('id', $this->opportunityId)
                    ->update(['portal_original' => $attempt % 2 === 0 ? '3CX' : 'Buscador']);
            }
        };

        $stats = $service->run(
            now()->parse(self::FROM)->toImmutable(),
            now()->parse(self::TO)->toImmutable(),
            true,
            'Persistent concurrency regression',
        );

        $this->assertTrue($stats['failed']);
        $this->assertSame(3, $stats['concurrency_retries']);
        $this->assertSame(1, $stats['concurrency_failures']);
        $this->assertNull($stats['last_local_id_processed']);
        $this->assertDatabaseCount('salesforce_opportunity_portal_reprocess_history', 0);
        $this->assertSame('Old portal', $opportunity->fresh()->portal_resolved);
        $this->assertSame(8, Cache::get('reservas_ventas_dashboard_cache_version'));
    }

    public function test_apply_mutex_blocks_second_apply_before_salesforce_but_not_dry_run(): void
    {
        $this->opportunity(['portal_original' => '3CX']);
        $fake = $this->fakeSalesforce(fn (): array => [$this->leadRecord()]);
        $lock = Cache::lock(OpportunityPortalReprocessService::APPLY_LOCK_KEY, 3600);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('reports:reprocess-opportunity-portals', $this->applyArguments())
                ->expectsOutputToContain('Ya existe otro reproceso')
                ->assertFailed();
            $this->assertSame([], $fake->queries);

            $this->artisan('reports:reprocess-opportunity-portals', [
                '--from' => self::FROM,
                '--to' => self::TO,
                '--dry-run' => true,
            ])->assertSuccessful();
        } finally {
            $lock->release();
        }

        $this->assertNotEmpty($fake->queries);
        $this->assertDatabaseCount('salesforce_opportunity_portal_reprocess_history', 0);
    }

    public function test_salesforce_failure_writes_nothing_and_reports_safe_error(): void
    {
        $opportunity = $this->opportunity(['portal_original' => '3CX', 'portal_resolved' => 'Old portal']);
        $this->fakeSalesforce(fn () => throw new \RuntimeException('Remote detail with synthetic@example.test'));

        $stats = $this->service()->run(
            now()->parse(self::FROM)->toImmutable(),
            now()->parse(self::TO)->toImmutable(),
            true,
            'Salesforce failure regression',
        );

        $this->assertTrue($stats['failed']);
        $this->assertSame(1, $stats['salesforce_lead_query_failures']);
        $this->assertStringNotContainsString('synthetic@example.test', $stats['error']);
        $this->assertSame('Old portal', $opportunity->fresh()->portal_resolved);
        $this->assertDatabaseCount('salesforce_opportunity_portal_reprocess_history', 0);
    }

    public function test_database_failure_rolls_back_update_and_history(): void
    {
        $opportunity = $this->opportunity(['portal_original' => 'COCHES.NET', 'portal_resolved' => 'Old portal']);
        $this->fakeSalesforce(fn (): array => []);
        Cache::forever('reservas_ventas_dashboard_cache_version', 5);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_opportunity_reprocess_history
BEFORE INSERT ON salesforce_opportunity_portal_reprocess_history
BEGIN
    SELECT RAISE(FAIL, 'synthetic opportunity history failure');
END
SQL);

        try {
            $stats = $this->service()->run(
                now()->parse(self::FROM)->toImmutable(),
                now()->parse(self::TO)->toImmutable(),
                true,
                'Database rollback regression',
            );
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_opportunity_reprocess_history');
        }

        $this->assertTrue($stats['failed']);
        $this->assertSame('Old portal', $opportunity->fresh()->portal_resolved);
        $this->assertDatabaseCount('salesforce_opportunity_portal_reprocess_history', 0);
        $this->assertSame(5, Cache::get('reservas_ventas_dashboard_cache_version'));
    }

    public function test_committed_first_chunk_survives_later_salesforce_failure_and_invalidates_cache_once(): void
    {
        foreach (range(1, 101) as $number) {
            $this->opportunity([
                'salesforce_id' => '006'.str_pad((string) $number, 12, '0', STR_PAD_LEFT),
                'portal_original' => 'Buscador',
                'portal_resolved' => 'Old portal',
                'account_phone' => '6'.str_pad((string) $number, 8, '0', STR_PAD_LEFT),
                'account_person_email' => null,
            ]);
        }
        $queryNumber = 0;
        $this->fakeSalesforce(function () use (&$queryNumber): array {
            $queryNumber++;

            if ($queryNumber === 3) {
                throw new \RuntimeException('Second chunk Salesforce failure');
            }

            return [];
        });
        Cache::forever('reservas_ventas_dashboard_cache_version', 10);

        $stats = $this->service()->run(
            now()->parse(self::FROM)->toImmutable(),
            now()->parse(self::TO)->toImmutable(),
            true,
            'Partial failure cache regression',
        );

        $hundredth = SalesforceOpportunity::query()->orderBy('id')->skip(99)->firstOrFail();
        $last = SalesforceOpportunity::query()->orderByDesc('id')->firstOrFail();
        $this->assertTrue($stats['failed']);
        $this->assertSame(100, $stats['rows_changed']);
        $this->assertSame($hundredth->id, $stats['last_local_id_processed']);
        $this->assertSame($hundredth->salesforce_id, $stats['last_salesforce_id_processed']);
        $this->assertSame('Web', $hundredth->portal_resolved);
        $this->assertSame('Old portal', $last->portal_resolved);
        $this->assertDatabaseCount('salesforce_opportunity_portal_reprocess_history', 100);
        $this->assertSame(11, Cache::get('reservas_ventas_dashboard_cache_version'));
        $this->assertSame(1, $stats['cache_invalidations']);
    }

    private function opportunity(array $overrides = []): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => '006'.str_pad((string) (SalesforceOpportunity::query()->count() + 1), 12, '0', STR_PAD_LEFT),
            'name' => 'Synthetic opportunity',
            'created_date' => '2026-05-15 10:00:00',
            'portal_original' => '3CX',
            'account_phone' => '600000001',
            'account_person_email' => 'synthetic@example.test',
            'account_company_email' => null,
            'raw_payload' => ['unrelated' => 'preserved'],
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function leadRecord(array $overrides = []): array
    {
        return array_merge([
            'Id' => '00Qsynthetic001',
            'CreatedDate' => '2026-05-10T10:00:00.000+0000',
            'Phone' => '600000001',
            'MobilePhone' => null,
            'Email' => 'synthetic@example.test',
            'Fuente_origen__c' => 'Coches.net',
            'Portal_Text__c' => 'Google Maps',
            'LEA_SEL_Fuente_Origen__c' => null,
            'Fuente_Nuevo__c' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function applyArguments(): array
    {
        return [
            '--from' => self::FROM,
            '--to' => self::TO,
            '--apply' => true,
            '--reason' => 'Controlled Opportunity portal reprocess',
        ];
    }

    private function service(): OpportunityPortalReprocessService
    {
        return $this->app->make(OpportunityPortalReprocessService::class);
    }

    private function fakeSalesforce(callable $handler): SalesforceClient
    {
        $fake = new class($handler) extends SalesforceClient
        {
            /** @var list<string> */
            public array $queries = [];

            public int $createCalls = 0;

            public int $updateCalls = 0;

            public function __construct(private $handler) {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return ($this->handler)($soql);
            }

            public function create(string $object, array $fields): string
            {
                $this->createCalls++;

                return 'not-used';
            }

            public function update(string $object, string $id, array $fields): void
            {
                $this->updateCalls++;
            }
        };

        $this->app->instance(SalesforceClient::class, $fake);
        $this->app->forgetInstance(SalesforceOpportunitySyncService::class);
        $this->app->forgetInstance(OpportunityPortalReprocessService::class);

        return $fake;
    }
}
