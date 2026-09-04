<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityDateRepairRun;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceOpportunityLifecycleDateRepairService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class SalesforceRepairOpportunityLifecycleDatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OPPORTUNITY_ID = '006ABCDEFGHIJKL';

    public function test_requiere_un_modo_exclusivo_y_motivo_para_apply(): void
    {
        $fake = $this->fakeSalesforce(fn (): array => []);

        $this->artisan('salesforce:repair-opportunity-lifecycle-dates')->assertFailed();
        $this->artisan('salesforce:repair-opportunity-lifecycle-dates', [
            '--dry-run' => true,
            '--apply' => true,
        ])->assertFailed();
        $this->artisan('salesforce:repair-opportunity-lifecycle-dates', [
            '--apply' => true,
            '--reason' => 'corto',
        ])->assertFailed();

        $this->assertSame([], $fake->queries);
        $this->assertDatabaseCount('salesforce_opportunity_date_repair_runs', 0);
    }

    public function test_dry_run_concilia_sin_escrituras_ni_auditoria(): void
    {
        $opportunity = $this->opportunity();
        $originalUpdatedAt = $opportunity->updated_at;
        $fake = $this->fakeSalesforce(fn (): array => [$this->salesforceRecord()]);

        [$exitCode, $metrics] = $this->runCommand(['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('dry-run', $metrics['mode']);
        $this->assertSame(1, $metrics['rows_examined']);
        $this->assertSame(1, $metrics['ids_found_in_salesforce']);
        $this->assertSame(1, $metrics['created_date_available']);
        $this->assertSame(1, $metrics['last_modified_date_available']);
        $this->assertSame(1, $metrics['rows_would_change']);
        $this->assertSame(0, $metrics['rows_changed']);

        $fresh = $opportunity->fresh();
        $this->assertNull($fresh->created_date);
        $this->assertNull($fresh->salesforce_last_modified_at);
        $this->assertTrue($fresh->updated_at->equalTo($originalUpdatedAt));
        $this->assertDatabaseCount('salesforce_opportunity_date_repair_runs', 0);
        $this->assertCount(1, $fake->queries);
        $this->assertStringContainsString('SELECT Id, CreatedDate, LastModifiedDate', $fake->queries[0]);
        $this->assertStringNotContainsString('Name', $fake->queries[0]);
    }

    public function test_apply_repara_solo_fechas_y_registra_motivo(): void
    {
        $opportunity = $this->opportunity();
        $opportunity->refresh();
        $before = $opportunity->getRawOriginal();
        $originalUpdatedAt = $opportunity->updated_at;
        $this->fakeSalesforce(fn (): array => [$this->salesforceRecord()]);

        $this->artisan('salesforce:repair-opportunity-lifecycle-dates', [
            '--apply' => true,
            '--reason' => 'Incidencia validada de fechas incompletas',
        ])
            ->expectsOutputToContain('"rows_changed":1')
            ->assertSuccessful();

        $fresh = $opportunity->fresh();
        $this->assertSame('2026-07-20 08:30:00', $fresh->created_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-29 10:45:00', $fresh->salesforce_last_modified_at->format('Y-m-d H:i:s'));
        $afterWithoutRepairedDates = $fresh->getRawOriginal();
        $afterWithoutRepairedDates['created_date'] = $before['created_date'];
        $afterWithoutRepairedDates['salesforce_last_modified_at'] = $before['salesforce_last_modified_at'];
        $this->assertSame($before, $afterWithoutRepairedDates);
        $this->assertSame(['unrelated' => 'preserved'], $fresh->raw_payload);
        $this->assertTrue($fresh->updated_at->equalTo($originalUpdatedAt));

        $run = SalesforceOpportunityDateRepairRun::query()->sole();
        $this->assertSame('Incidencia validada de fechas incompletas', $run->reason);
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->rows_examined);
        $this->assertSame(1, $run->rows_changed);
        $this->assertNotNull($run->finished_at);
    }

    public function test_ids_invalidos_missing_y_respuestas_inesperadas_no_crean_filas(): void
    {
        $this->opportunity(['salesforce_id' => 'not-valid']);
        $this->opportunity(['salesforce_id' => self::OPPORTUNITY_ID]);
        $this->fakeSalesforce(fn (): array => [[
            'Id' => '006ZZZZZZZZZZZZZ',
            'CreatedDate' => '2026-07-20T08:30:00.000Z',
            'LastModifiedDate' => '2026-07-29T10:45:00.000Z',
        ]]);

        [$exitCode, $metrics] = $this->runCommand(['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $metrics['ids_invalid']);
        $this->assertSame(1, $metrics['ids_not_found_in_salesforce']);
        $this->assertSame(0, $metrics['rows_would_change']);

        $this->assertDatabaseCount('salesforce_opportunities', 2);
        $this->assertDatabaseMissing('salesforce_opportunities', ['salesforce_id' => '006ZZZZZZZZZZZZZ']);
    }

    public function test_limit_y_cursor_operan_sobre_id_local_estable(): void
    {
        $first = $this->opportunity(['salesforce_id' => '006AAAAAAAAAAAA']);
        $second = $this->opportunity(['salesforce_id' => '006BBBBBBBBBBBB']);
        $third = $this->opportunity(['salesforce_id' => '006CCCCCCCCCCCC']);
        $fake = $this->fakeSalesforce(fn (): array => [[
            'Id' => '006BBBBBBBBBBBB',
            'CreatedDate' => '2026-07-20T08:30:00.000Z',
            'LastModifiedDate' => '2026-07-29T10:45:00.000Z',
        ]]);

        [$exitCode, $metrics] = $this->runCommand([
            '--dry-run' => true,
            '--after-id' => $first->id,
            '--limit' => 1,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $metrics['rows_examined']);
        $this->assertSame($second->id, $metrics['last_local_id_processed']);

        $this->assertCount(1, $fake->queries);
        $this->assertStringContainsString('006BBBBBBBBBBBB', $fake->queries[0]);
        $this->assertStringNotContainsString('006AAAAAAAAAAAA', $fake->queries[0]);
        $this->assertStringNotContainsString('006CCCCCCCCCCCC', $fake->queries[0]);
        $this->assertNull($third->fresh()->created_date);
    }

    public function test_apply_concurrente_falla_antes_de_consultar_salesforce(): void
    {
        $this->opportunity();
        $fake = $this->fakeSalesforce(fn (): array => [$this->salesforceRecord()]);
        $lock = Cache::lock(SalesforceOpportunityLifecycleDateRepairService::APPLY_LOCK_KEY, 3600);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('salesforce:repair-opportunity-lifecycle-dates', [
                '--apply' => true,
                '--reason' => 'Segunda ejecución simultánea bloqueada',
            ])
                ->expectsOutputToContain('Ya existe otra reparacion')
                ->assertFailed();
        } finally {
            $lock->release();
        }

        $this->assertSame([], $fake->queries);
        $this->assertDatabaseCount('salesforce_opportunity_date_repair_runs', 0);
    }

    public function test_apply_no_sobrescribe_una_fecha_completada_antes_del_lock(): void
    {
        $opportunity = $this->opportunity();
        $this->fakeSalesforce(fn (): array => [$this->salesforceRecord()]);
        $service = new class($this->app->make(SalesforceClient::class), $opportunity->id) extends SalesforceOpportunityLifecycleDateRepairService
        {
            public function __construct(SalesforceClient $client, private readonly int $targetId)
            {
                parent::__construct($client);
            }

            protected function beforeApplyTransaction(array $localIds): void
            {
                SalesforceOpportunity::query()->whereKey($this->targetId)->update([
                    'created_date' => '2026-07-19 07:00:00',
                    'salesforce_last_modified_at' => '2026-07-28 09:00:00',
                ]);
            }
        };

        $stats = $service->run(true, 'Concurrencia controlada para prueba');

        $fresh = $opportunity->fresh();
        $this->assertSame(0, $stats['rows_changed']);
        $this->assertSame(1, $stats['rows_skipped_concurrent']);
        $this->assertSame('2026-07-19 07:00:00', $fresh->created_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-28 09:00:00', $fresh->salesforce_last_modified_at->format('Y-m-d H:i:s'));
        $this->assertSame(['unrelated' => 'preserved'], $fresh->raw_payload);
    }

    public function test_last_modified_puede_repararse_si_salesforce_no_devuelve_created_date(): void
    {
        $opportunity = $this->opportunity();
        $this->fakeSalesforce(fn (): array => [[
            'Id' => self::OPPORTUNITY_ID.'XYZ',
            'CreatedDate' => null,
            'LastModifiedDate' => '2026-07-29T10:45:00.000Z',
        ]]);

        [$exitCode, $metrics] = $this->runCommand([
            '--apply' => true,
            '--reason' => 'Reparación parcial acreditada por Salesforce',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $metrics['created_date_available']);
        $this->assertSame(1, $metrics['last_modified_date_available']);
        $this->assertSame(1, $metrics['rows_changed']);

        $fresh = $opportunity->fresh();
        $this->assertNull($fresh->created_date);
        $this->assertSame('2026-07-29 10:45:00', $fresh->salesforce_last_modified_at->format('Y-m-d H:i:s'));
    }

    public function test_procesa_como_maximo_cien_salesforce_ids_por_consulta(): void
    {
        foreach (range(1, 101) as $index) {
            $this->opportunity([
                'salesforce_id' => '006'.str_pad((string) $index, 12, '0', STR_PAD_LEFT),
            ]);
        }
        $fake = $this->fakeSalesforce(fn (): array => []);

        [$exitCode, $metrics] = $this->runCommand(['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(101, $metrics['rows_examined']);
        $this->assertSame(101, $metrics['ids_consulted']);
        $this->assertCount(2, $fake->queries);
        foreach ($fake->queries as $query) {
            preg_match_all("/'006[A-Za-z0-9]{12}(?:[A-Za-z0-9]{3})?'/", $query, $matches);
            $this->assertLessThanOrEqual(100, count($matches[0]));
        }
    }

    public function test_fallo_salesforce_no_escribe_fechas_y_deja_run_apply_fallido(): void
    {
        $opportunity = $this->opportunity();
        $this->fakeSalesforce(function (): never {
            throw new RuntimeException('Fallo remoto sintético');
        });

        [$exitCode, $metrics] = $this->runCommand([
            '--apply' => true,
            '--reason' => 'Fallo remoto controlado para prueba',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertTrue($metrics['failed']);
        $this->assertNull($metrics['last_local_id_processed']);
        $this->assertNull($opportunity->fresh()->created_date);
        $this->assertDatabaseHas('salesforce_opportunity_date_repair_runs', [
            'reason' => 'Fallo remoto controlado para prueba',
            'status' => 'failed',
            'rows_changed' => 0,
        ]);
    }

    private function opportunity(array $overrides = []): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => self::OPPORTUNITY_ID,
            'name' => 'Dato sintético',
            'created_date' => null,
            'salesforce_last_modified_at' => null,
            'stage_name' => 'Cerrada ganada',
            'record_type_name' => 'Venta',
            'amount' => 25000,
            'opo_for_importe_total' => 24500,
            'portal_original' => 'Coches.net',
            'portal_resolved' => 'Coches.net',
            'portal_resolution_source' => 'opportunity',
            'opportunity_source_raw' => 'Portal',
            'opportunity_source_normalized' => 'portal',
            'reservation' => true,
            'reservation_date' => '2026-07-18',
            'cv_signed' => true,
            'cv_signed_date' => '2026-07-20',
            'raw_payload' => ['unrelated' => 'preserved'],
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function salesforceRecord(): array
    {
        return [
            'Id' => self::OPPORTUNITY_ID.'XYZ',
            'CreatedDate' => '2026-07-20T08:30:00.000Z',
            'LastModifiedDate' => '2026-07-29T10:45:00.000Z',
        ];
    }

    private function fakeSalesforce(callable $handler): object
    {
        $fake = new class(Closure::fromCallable($handler)) extends SalesforceClient
        {
            /** @var list<string> */
            public array $queries = [];

            public function __construct(private readonly Closure $handler) {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return ($this->handler)($soql);
            }
        };

        $this->app->instance(SalesforceClient::class, $fake);
        $this->app->forgetInstance(SalesforceOpportunityLifecycleDateRepairService::class);

        return $fake;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function runCommand(array $arguments): array
    {
        $exitCode = Artisan::call('salesforce:repair-opportunity-lifecycle-dates', $arguments);
        $matched = preg_match('/^OPPORTUNITY_DATE_REPAIR_METRICS=(.+)$/m', Artisan::output(), $matches);
        $this->assertSame(1, $matched);

        return [
            $exitCode,
            json_decode(trim($matches[1]), true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
