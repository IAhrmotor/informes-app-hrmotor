<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityPresenceReconciliationRun;
use App\Models\SalesforceSaleSnapshot;
use App\Services\Reports\Stock\StockSaleValidityService;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceOpportunityPresenceReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesforceReconcileOpportunityPresenceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_esquema_lifecycle_es_aditivo_y_el_estado_activo_es_el_default(): void
    {
        $this->assertTrue(Schema::hasColumns('salesforce_opportunities', [
            'is_deleted',
            'salesforce_deleted_at',
            'deletion_detection_source',
        ]));

        $row = $this->opportunity('006LLLLLLLLLLLL');
        $uncertain = $this->opportunity('006OOOOOOOOOOOO', [
            'is_deleted' => true,
            'deletion_detection_source' => SalesforceOpportunity::PRESENCE_SOURCE_MISSING,
        ]);

        $this->assertFalse($row->fresh()->is_deleted);
        $this->assertNotNull(SalesforceOpportunity::query()->find($uncertain->id));
        $this->assertSame(2, SalesforceOpportunity::query()->count());
    }

    public function test_dry_run_distingue_borrada_ausente_activa_e_id_invalido_sin_escribir(): void
    {
        $active = $this->opportunity('006AAAAAAAAAAAA');
        $deleted = $this->opportunity('006BBBBBBBBBBBB');
        $missing = $this->opportunity('006CCCCCCCCCCCC');
        $invalid = $this->opportunity('invalid-id');
        $queriedSoql = '';
        $this->bindClient(function (string $soql) use ($active, $deleted, &$queriedSoql): array {
            $queriedSoql = $soql;

            return [
                ['Id' => $active->salesforce_id, 'IsDeleted' => false, 'SystemModstamp' => '2026-09-01T10:00:00Z'],
                ['Id' => $deleted->salesforce_id, 'IsDeleted' => true, 'SystemModstamp' => '2026-09-01T11:00:00Z'],
            ];
        });

        $this->artisan('salesforce:reconcile-opportunity-presence --dry-run')
            ->expectsOutputToContain('OPPORTUNITY_PRESENCE_METRICS=')
            ->assertSuccessful();

        $this->assertDatabaseHas('salesforce_opportunities', ['id' => $deleted->id, 'is_deleted' => false]);
        $this->assertDatabaseHas('salesforce_opportunities', ['id' => $missing->id, 'is_deleted' => false]);
        $this->assertDatabaseHas('salesforce_opportunities', ['id' => $invalid->id, 'is_deleted' => false]);
        $this->assertDatabaseCount('salesforce_opportunity_presence_reconciliation_runs', 0);
        $this->assertStringContainsString('SystemModstamp', $queriedSoql);
        $this->assertStringNotContainsString('SystemModStamp', $queriedSoql);
    }

    public function test_exige_un_modo_exacto_y_motivo_descriptivo_para_apply(): void
    {
        $this->artisan('salesforce:reconcile-opportunity-presence')->assertFailed();
        $this->artisan('salesforce:reconcile-opportunity-presence --dry-run --apply --reason="Motivo suficientemente largo"')->assertFailed();
        $this->artisan('salesforce:reconcile-opportunity-presence --apply --reason="corto"')->assertFailed();
    }

    public function test_apply_distingue_borrado_ausencia_y_reaparicion_pendiente_de_refresh_canonico(): void
    {
        $deleted = $this->opportunity('006DDDDDDDDDDDD', [
            'portal_resolved' => 'Coches.net',
            'raw_payload' => ['preserve' => true],
        ]);
        $missing = $this->opportunity('006EEEEEEEEEEEE', ['portal_resolved' => 'Web']);
        $reactivated = $this->opportunity('006FFFFFFFFFFFF', [
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-08-01 10:00:00',
            'deletion_detection_source' => 'query_all_deleted',
        ]);
        $deletedSnapshot = SalesforceSaleSnapshot::query()->create([
            'opportunity_salesforce_id' => $deleted->salesforce_id,
            'record_type' => 'Venta',
            'signed_date' => '2026-07-20',
            'vehicle_salesforce_id' => '01t-deleted-presence',
            'sale_price' => 18000,
            'is_valid' => true,
            'captured_at' => now(),
        ]);
        $this->bindClient(fn (string $soql): array => [
            ['Id' => $deleted->salesforce_id, 'IsDeleted' => true, 'SystemModstamp' => '2026-09-01T11:00:00Z'],
            ['Id' => $reactivated->salesforce_id, 'IsDeleted' => false, 'SystemModstamp' => '2026-09-01T12:00:00Z'],
        ]);

        $this->artisan('salesforce:reconcile-opportunity-presence --apply --reason="Conciliacion controlada de presencia"')
            ->expectsOutputToContain('"rows_pending_canonical_refresh":1')
            ->expectsOutputToContain('STOCK_SALE_VALIDITY_METRICS=')
            ->assertSuccessful();

        $this->assertDatabaseHas('salesforce_opportunities', [
            'id' => $deleted->id,
            'portal_resolved' => 'Coches.net',
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-09-01 11:00:00',
            'deletion_detection_source' => 'query_all_deleted',
        ]);
        $this->assertSame(['preserve' => true], SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)->findOrFail($deleted->id)->raw_payload);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'id' => $missing->id,
            'portal_resolved' => 'Web',
            'is_deleted' => false,
            'salesforce_deleted_at' => null,
            'deletion_detection_source' => 'presence_reconciliation_missing',
        ]);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'id' => $reactivated->id,
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-08-01 10:00:00',
            'deletion_detection_source' => 'query_all_deleted',
        ]);
        $this->assertNotNull(SalesforceOpportunity::query()->find($missing->id));
        $this->assertNull(SalesforceOpportunity::query()->find($reactivated->id));
        $this->assertDatabaseCount('salesforce_opportunity_presence_reconciliation_runs', 1);
        $this->assertSame(2, SalesforceOpportunityPresenceReconciliationRun::query()->value('rows_changed'));
        $this->assertFalse($deletedSnapshot->fresh()->is_valid);
        $this->assertSame(
            StockSaleValidityService::REASON_OPPORTUNITY_CONFIRMED_DELETED,
            $deletedSnapshot->fresh()->invalid_reason,
        );

        $this->artisan('salesforce:reconcile-opportunity-presence --apply --reason="Segunda conciliacion idempotente"')
            ->assertSuccessful();
        $this->assertSame(0, SalesforceOpportunityPresenceReconciliationRun::query()->latest('id')->value('rows_changed'));
    }

    public function test_metricas_incluyen_muestra_acotada_de_reactivaciones_pendientes(): void
    {
        $reactivated = $this->opportunity('006PPPPPPPPPPPP', [
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-08-01 10:00:00',
            'deletion_detection_source' => SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL,
        ]);
        $this->bindClient(fn (string $soql): array => [[
            'Id' => $reactivated->salesforce_id,
            'IsDeleted' => false,
            'SystemModstamp' => '2026-09-01T12:00:00Z',
        ]]);

        $stats = app(SalesforceOpportunityPresenceReconciliationService::class)->run(false);

        $this->assertSame(1, $stats['rows_pending_canonical_refresh']);
        $this->assertSame(
            [$reactivated->salesforce_id],
            $stats['samples']['pending_canonical_refresh_salesforce_ids'],
        );
    }

    public function test_borrado_confirmado_sin_system_modstamp_es_idempotente_y_conserva_fecha_nula(): void
    {
        $deleted = $this->opportunity('006QQQQQQQQQQQQ');
        $this->bindClient(fn (string $soql): array => [[
            'Id' => $deleted->salesforce_id,
            'IsDeleted' => true,
            'SystemModstamp' => null,
        ]]);

        $first = app(SalesforceOpportunityPresenceReconciliationService::class)
            ->run(true, 'Conciliacion controlada sin timestamp tecnico');

        $this->assertSame(1, $first['rows_changed']);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'id' => $deleted->id,
            'is_deleted' => true,
            'salesforce_deleted_at' => null,
            'deletion_detection_source' => SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL,
        ]);

        $second = app(SalesforceOpportunityPresenceReconciliationService::class)
            ->run(true, 'Segunda conciliacion idempotente sin timestamp');

        $this->assertSame(0, $second['rows_changed']);
        $this->assertSame(1, $second['rows_unchanged']);
    }

    public function test_limit_y_cursor_procesan_lotes_deterministas(): void
    {
        $first = $this->opportunity('006GGGGGGGGGGGG');
        $second = $this->opportunity('006HHHHHHHHHHHH');
        $third = $this->opportunity('006IIIIIIIIIIII');
        $queries = [];
        $this->bindClient(function (string $soql) use (&$queries): array {
            $queries[] = $soql;

            return [];
        });

        $this->artisan("salesforce:reconcile-opportunity-presence --dry-run --limit=1 --after-id={$first->id}")
            ->assertSuccessful();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString($second->salesforce_id, $queries[0]);
        $this->assertStringNotContainsString($first->salesforce_id, $queries[0]);
        $this->assertStringNotContainsString($third->salesforce_id, $queries[0]);
    }

    public function test_mas_de_cien_filas_se_consultan_en_chunks_sin_n_mas_uno(): void
    {
        foreach (range(1, 101) as $index) {
            $this->opportunity('006'.str_pad((string) $index, 12, '0', STR_PAD_LEFT));
        }
        $calls = 0;
        $this->bindClient(function (string $soql) use (&$calls): array {
            $calls++;

            return [];
        });

        $this->artisan('salesforce:reconcile-opportunity-presence --dry-run')->assertSuccessful();

        $this->assertSame(2, $calls);
    }

    public function test_mutex_impide_segundo_apply_antes_de_consultar_salesforce(): void
    {
        $this->opportunity('006JJJJJJJJJJJJ');
        $calls = 0;
        $this->bindClient(function (string $soql) use (&$calls): array {
            $calls++;

            return [];
        });
        $lock = Cache::lock(SalesforceOpportunityPresenceReconciliationService::APPLY_LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('salesforce:reconcile-opportunity-presence --apply --reason="Ejecucion concurrente controlada"')
                ->expectsOutputToContain('Ya existe otra reconciliacion')
                ->assertFailed();
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $calls);
        $this->assertDatabaseCount('salesforce_opportunity_presence_reconciliation_runs', 0);
    }

    public function test_error_salesforce_falla_de_forma_controlada_sin_escrituras(): void
    {
        $row = $this->opportunity('006KKKKKKKKKKKK');
        $this->bindClient(fn (string $soql): never => throw new \RuntimeException('error remoto sensible'));

        $this->artisan('salesforce:reconcile-opportunity-presence --dry-run')
            ->expectsOutputToContain('OPPORTUNITY_PRESENCE_METRICS=')
            ->assertFailed();

        $this->assertDatabaseHas('salesforce_opportunities', ['id' => $row->id, 'is_deleted' => false]);
    }

    public function test_apply_no_pisa_una_fila_modificada_entre_consulta_remota_y_lock(): void
    {
        $row = $this->opportunity('006MMMMMMMMMMMM', ['name' => 'Antes']);
        $client = new class($row->salesforce_id) extends SalesforceClient
        {
            public function __construct(private readonly string $salesforceId) {}

            public function queryAll(string $soql): array
            {
                return [[
                    'Id' => $this->salesforceId,
                    'IsDeleted' => true,
                    'SystemModstamp' => '2026-09-01T11:00:00Z',
                ]];
            }
        };
        $service = new class($client) extends SalesforceOpportunityPresenceReconciliationService
        {
            public function __construct(SalesforceClient $client)
            {
                parent::__construct($client);
            }

            protected function beforeApplyTransaction(array $localIds): void
            {
                DB::table('salesforce_opportunities')->whereIn('id', $localIds)->update([
                    'name' => 'Concurrente',
                    'updated_at' => '2099-01-01 00:00:00',
                ]);
            }
        };

        $stats = $service->run(true, 'Prueba de concurrencia controlada');

        $this->assertSame(1, $stats['rows_skipped_concurrent']);
        $this->assertSame(0, $stats['rows_changed']);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'id' => $row->id,
            'name' => 'Concurrente',
            'is_deleted' => false,
        ]);
    }

    public function test_apply_detecta_cambio_lifecycle_concurrente_aunque_updated_at_no_cambie(): void
    {
        $row = $this->opportunity('006NNNNNNNNNNNN');
        $originalUpdatedAt = $row->getRawOriginal('updated_at');
        $client = new class($row->salesforce_id) extends SalesforceClient
        {
            public function __construct(private readonly string $salesforceId) {}

            public function queryAll(string $soql): array
            {
                return [[
                    'Id' => $this->salesforceId,
                    'IsDeleted' => true,
                    'SystemModstamp' => '2026-09-01T11:00:00Z',
                ]];
            }
        };
        $service = new class($client, $originalUpdatedAt) extends SalesforceOpportunityPresenceReconciliationService
        {
            public function __construct(SalesforceClient $client, private readonly string $updatedAt)
            {
                parent::__construct($client);
            }

            protected function beforeApplyTransaction(array $localIds): void
            {
                DB::table('salesforce_opportunities')->whereIn('id', $localIds)->update([
                    'is_deleted' => false,
                    'deletion_detection_source' => SalesforceOpportunity::PRESENCE_SOURCE_MISSING,
                    'updated_at' => $this->updatedAt,
                ]);
            }
        };

        $stats = $service->run(true, 'Prueba lifecycle concurrente mismo segundo');

        $this->assertSame(1, $stats['rows_skipped_concurrent']);
        $this->assertSame(0, $stats['rows_changed']);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'id' => $row->id,
            'is_deleted' => false,
            'deletion_detection_source' => SalesforceOpportunity::PRESENCE_SOURCE_MISSING,
        ]);
    }

    private function bindClient(callable $handler): void
    {
        $this->app->bind(SalesforceClient::class, fn () => new class($handler) extends SalesforceClient
        {
            public function __construct(private $handler) {}

            public function queryAll(string $soql): array
            {
                return ($this->handler)($soql);
            }
        });
    }

    private function opportunity(string $salesforceId, array $overrides = []): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => $salesforceId,
            'name' => $salesforceId,
            'created_date' => null,
            'portal_resolved' => 'Sin clasificar',
            'raw_payload' => ['baseline' => true],
        ], $overrides));
    }
}
