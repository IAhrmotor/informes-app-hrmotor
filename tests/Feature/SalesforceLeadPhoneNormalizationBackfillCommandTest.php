<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Services\Salesforce\SalesforceLeadPhoneNormalizationBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesforceLeadPhoneNormalizationBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_requiere_exactamente_un_modo_y_valida_limite_y_cursor(): void
    {
        $this->artisan('salesforce:backfill-lead-phone-normalization')->assertFailed();
        $this->artisan('salesforce:backfill-lead-phone-normalization', [
            '--dry-run' => true,
            '--apply' => true,
        ])->assertFailed();
        $this->artisan('salesforce:backfill-lead-phone-normalization', [
            '--dry-run' => true,
            '--limit' => '0',
        ])->assertFailed();
        $this->artisan('salesforce:backfill-lead-phone-normalization', [
            '--dry-run' => true,
            '--after-id' => '0',
        ])->assertFailed();
    }

    public function test_dry_run_no_escribe_y_no_expone_telefonos(): void
    {
        $lead = $this->lead(['phone' => '+34 612 34 56 78', 'mobile_phone' => null]);

        [$exitCode, $metrics, $output] = $this->runCommand(['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('dry-run', $metrics['mode']);
        $this->assertSame(1, $metrics['rows_examined']);
        $this->assertSame(1, $metrics['rows_changed']);
        $this->assertSame(1, $metrics['phone_keys_non_null']);
        $this->assertSame(9, $metrics['max_normalized_length']);
        $this->assertNull($lead->fresh()->phone_normalized);
        $this->assertStringNotContainsString('612345678', $output);
        $this->assertStringNotContainsString('+34 612 34 56 78', $output);
    }

    public function test_apply_actualiza_solo_derivados_y_es_idempotente(): void
    {
        $lead = $this->lead([
            'phone' => '623 45 67 89',
            'mobile_phone' => '+34 624-56-78-90',
            'source_origin_new' => 'Coches.net',
            'raw_payload' => ['unrelated' => 'preserved'],
        ]);
        $originalUpdatedAt = $lead->updated_at;

        [, $first] = $this->runCommand(['--apply' => true]);
        [, $second] = $this->runCommand(['--apply' => true]);

        $fresh = $lead->fresh();
        $this->assertSame(1, $first['rows_changed']);
        $this->assertSame(0, $second['rows_changed']);
        $this->assertSame(1, $second['rows_unchanged']);
        $this->assertSame('623456789', $fresh->phone_normalized);
        $this->assertSame('624567890', $fresh->mobile_phone_normalized);
        $this->assertSame('623 45 67 89', $fresh->phone);
        $this->assertSame('+34 624-56-78-90', $fresh->mobile_phone);
        $this->assertSame('Coches.net', $fresh->source_origin_new);
        $this->assertSame(['unrelated' => 'preserved'], $fresh->raw_payload);
        $this->assertTrue($fresh->updated_at->equalTo($originalUpdatedAt));
    }

    public function test_limit_y_cursor_procesan_por_id_local(): void
    {
        $first = $this->lead(['salesforce_id' => '00QFIRST0000001', 'phone' => '611111111']);
        $second = $this->lead(['salesforce_id' => '00QSECOND000001', 'phone' => '622222222']);
        $third = $this->lead(['salesforce_id' => '00QTHIRD0000001', 'phone' => '633333333']);

        [, $metrics] = $this->runCommand([
            '--apply' => true,
            '--after-id' => $first->id,
            '--limit' => 1,
        ]);

        $this->assertSame(1, $metrics['rows_examined']);
        $this->assertSame($second->id, $metrics['last_local_id_processed']);
        $this->assertNull($first->fresh()->phone_normalized);
        $this->assertSame('622222222', $second->fresh()->phone_normalized);
        $this->assertNull($third->fresh()->phone_normalized);
    }

    public function test_apply_concurrente_falla_sin_escrituras(): void
    {
        $lead = $this->lead(['phone' => '644444444']);
        $lock = Cache::lock(SalesforceLeadPhoneNormalizationBackfillService::APPLY_LOCK_KEY, 3600);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('salesforce:backfill-lead-phone-normalization', ['--apply' => true])
                ->expectsOutputToContain('Ya existe otro backfill')
                ->assertFailed();
        } finally {
            $lock->release();
        }

        $this->assertNull($lead->fresh()->phone_normalized);
    }

    public function test_procesa_mas_de_un_lote_sin_updates_por_fila(): void
    {
        $now = now();
        $rows = collect(range(1, 501))->map(fn (int $index): array => [
            'salesforce_id' => '00Q'.str_pad((string) $index, 12, '0', STR_PAD_LEFT),
            'created_date' => '2026-05-10 10:00:00',
            'phone' => '6'.str_pad((string) $index, 8, '0', STR_PAD_LEFT),
            'is_deleted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('salesforce_leads')->insert($chunk);
        }
        $updates = 0;
        DB::listen(function ($query) use (&$updates): void {
            if (str_starts_with(strtolower($query->sql), 'update "salesforce_leads"')) {
                $updates++;
            }
        });

        [, $metrics] = $this->runCommand(['--apply' => true]);

        $this->assertSame(501, $metrics['rows_examined']);
        $this->assertSame(501, $metrics['rows_changed']);
        $this->assertSame(2, $updates);
        $this->assertSame('600000001', SalesforceLead::query()->orderBy('id')->value('phone_normalized'));
    }

    private function lead(array $overrides = []): SalesforceLead
    {
        return SalesforceLead::query()->create(array_merge([
            'salesforce_id' => '00QBACKFILL00001',
            'created_date' => '2026-05-10 10:00:00',
            'phone' => null,
            'mobile_phone' => null,
            'is_deleted' => false,
        ], $overrides));
    }

    /** @return array{0:int,1:array<string,mixed>,2:string} */
    private function runCommand(array $arguments): array
    {
        $exitCode = Artisan::call('salesforce:backfill-lead-phone-normalization', $arguments);
        $output = Artisan::output();
        $matched = preg_match('/^LEAD_PHONE_NORMALIZATION_METRICS=(.+)$/m', $output, $matches);
        $this->assertSame(1, $matched);

        return [
            $exitCode,
            json_decode(trim($matches[1]), true, 512, JSON_THROW_ON_ERROR),
            $output,
        ];
    }
}
