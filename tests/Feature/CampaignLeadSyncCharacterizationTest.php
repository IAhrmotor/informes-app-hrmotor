<?php

namespace Tests\Feature;

use App\Models\CampaignSalesforceLead;
use App\Models\SalesforceLead;
use App\Services\Campaigns\CampaignLeadSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampaignLeadSyncCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_soql_filtrado_congela_select_y_universo_legacy_actual(): void
    {
        $service = new CampaignLeadSyncService($this->createMock(SalesforceClient::class));
        $soql = $service->soql(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-06-01'));

        foreach ([
            'Campa_a_Adquirida__c',
            'Id_Adquirido__c',
            'Contenido_Adquirido__c',
            'LEA_SEL_Fuente_Origen__c',
            'LEA_SEL_Medio_Origen__c',
        ] as $field) {
            $this->assertStringContainsString("{$field} != null", $soql);
            $this->assertSame(2, substr_count($soql, $field), "{$field} debe aparecer una vez en SELECT y una vez en WHERE");
        }

        foreach ([
            'Fuente_Adquirida__c',
            'Medio_Adquirido__c',
            'Fuente_origen__c',
            'Medio_origen__c',
            'Canal__c',
            'Delegacion_procedencia__c',
            'utm_campaign__c',
            'utm_id__c',
            'utm_source__c',
            'utm_medium__c',
            'utm_content__c',
        ] as $field) {
            $this->assertStringContainsString($field, $soql);
            $this->assertStringNotContainsString("{$field} != null", $soql);
        }
    }

    public function test_query_por_rango_conserva_select_legacy_pero_no_filtra_adquisicion(): void
    {
        $service = new CampaignLeadSyncService($this->createMock(SalesforceClient::class));
        $soql = $service->rangeSoql(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-06-01'));

        foreach ([
            'Campa_a_Adquirida__c',
            'Id_Adquirido__c',
            'Contenido_Adquirido__c',
            'LEA_SEL_Fuente_Origen__c',
            'LEA_SEL_Medio_Origen__c',
        ] as $field) {
            $this->assertStringContainsString($field, $soql);
            $this->assertStringNotContainsString("{$field} != null", $soql);
        }
    }

    public function test_filtro_php_acepta_cada_candidato_legacy_y_excluye_lead_sin_adquisicion(): void
    {
        $records = [
            $this->record('00Q-campaign', ['Campa_a_Adquirida__c' => 'Campaña']),
            $this->record('00Q-id', ['Id_Adquirido__c' => 'campaign-id']),
            $this->record('00Q-content', ['Contenido_Adquirido__c' => 'creative-id']),
            $this->record('00Q-source', ['LEA_SEL_Fuente_Origen__c' => 'Meta']),
            $this->record('00Q-medium', ['LEA_SEL_Medio_Origen__c' => 'Formulario']),
            $this->record('00Q-empty', []),
            $this->record('00Q-whitespace', ['Campa_a_Adquirida__c' => '   ']),
            $this->record('00Q-utm-only', [
                'utm_campaign__c' => 'Campaña UTM nueva',
                'utm_id__c' => 'utm-id',
                'utm_source__c' => 'google',
                'utm_medium__c' => 'cpc',
                'utm_content__c' => 'creative',
            ]),
        ];
        $client = $this->createMock(SalesforceClient::class);
        $client->expects($this->once())->method('query')->willReturn($records);

        $result = (new CampaignLeadSyncService($client))->sync(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            fresh: true,
            dryRun: true,
        );

        $this->assertSame(8, $result['queried']);
        $this->assertSame(0, $result['saved']);
        $this->assertSame(1, $result['with_campaign_acquired']);
        $this->assertSame(1, $result['with_acquired_id']);
        $this->assertSame(1, $result['with_content_acquired']);
        $this->assertSame(1, $result['with_fuente_origen']);
        $this->assertSame(1, $result['with_medio_origen']);
        $this->assertSame(3, $result['without_acquisition']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, $result['deleted']);
        $this->assertDatabaseCount('campaign_salesforce_leads', 0);
        $this->assertDatabaseCount('salesforce_leads', 0);
    }

    public function test_fallo_de_query_filtrada_reintenta_por_rango_y_filtra_en_php(): void
    {
        $queries = [];
        $client = $this->createMock(SalesforceClient::class);
        $client->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $soql) use (&$queries): array {
                $queries[] = $soql;

                if (count($queries) === 1) {
                    throw new \RuntimeException('INVALID_FIELD synthetic');
                }

                return [
                    $this->record('00Q-valid', ['Id_Adquirido__c' => 'campaign-id']),
                    $this->record('00Q-empty', []),
                ];
            });

        $result = (new CampaignLeadSyncService($client))->sync(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
            dryRun: true,
        );

        $this->assertStringContainsString('Id_Adquirido__c != null', $queries[0]);
        $this->assertStringNotContainsString('Id_Adquirido__c != null', $queries[1]);
        $this->assertCount(1, $result['warnings']);
        $this->assertSame(1, $result['with_acquired_id']);
        $this->assertSame(1, $result['without_acquisition']);
    }

    public function test_upsert_de_campanas_preserva_campos_generales_y_actualiza_solo_adquisicion_valida(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-shared',
            'name' => 'Nombre mensual',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Estado mensual',
            'owner_id' => '005-monthly-owner',
            'owner_name' => 'Owner mensual',
            'phone' => '600000001',
            'email' => 'monthly@example.test',
            'record_type_name' => 'Venta',
            'record_type_normalized' => 'venta',
            'fuente_origen' => 'Fuente mensual',
            'medio_origen' => 'Medio mensual',
            'campaign_acquired' => 'Campaña anterior',
            'acquired_id' => 'id-anterior',
            'content_acquired' => 'content-anterior',
            'acquired_source_legacy' => 'legacy-source-anterior',
            'acquired_medium_legacy' => 'legacy-medium-anterior',
            'utm_campaign_new' => 'campaign-new-existente',
            'utm_id_new' => 'id-new-anterior',
            'utm_source_new' => 'source-anterior',
            'utm_medium_new' => 'medium-new-anterior',
            'utm_content_new' => 'content-new-anterior',
            'field_resolution' => [
                'source' => ['effective_value' => 'Meta'],
                'channel' => ['effective_value' => 'WhatsApp'],
                'medium' => ['effective_value' => 'Paid Social'],
                'delegation' => ['effective_value' => 'Alcobendas'],
                'utm_campaign' => ['effective_value' => 'Resolución obsoleta'],
            ],
            'resolved_portal' => 'Portal mensual',
            'portal_resolution_source' => 'Portal_Text__c',
            'raw_payload' => ['scope' => 'monthly', 'complete' => true],
        ]);

        $client = $this->createMock(SalesforceClient::class);
        $client->expects($this->once())->method('query')->willReturn([
            $this->record('00Q-shared', [
                'Name' => 'Nombre campaña',
                'Status' => 'Estado campaña',
                'Phone' => '600000999',
                'Email' => 'campaign@example.test',
                'Campa_a_Adquirida__c' => 'Campaña adquirida',
                'Id_Adquirido__c' => '   ',
                'Contenido_Adquirido__c' => 'content-legacy-nuevo',
                'Fuente_Adquirida__c' => 'legacy-source-nuevo',
                'Medio_Adquirido__c' => null,
                'utm_campaign__c' => '   ',
                'utm_id__c' => 'id-new-nuevo',
                'utm_source__c' => 'source-nuevo',
                'utm_medium__c' => null,
                'utm_content__c' => 'content-new-nuevo',
            ]),
        ]);

        $result = (new CampaignLeadSyncService($client))->sync(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
        );

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('campaign_salesforce_leads', [
            'salesforce_id' => '00Q-shared',
            'name' => 'Nombre campaña',
            'campaign_acquired' => 'Campaña adquirida',
        ]);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-shared',
            'name' => 'Nombre mensual',
            'status' => 'Estado mensual',
            'owner_id' => '005-monthly-owner',
            'owner_name' => 'Owner mensual',
            'phone' => '600000001',
            'email' => 'monthly@example.test',
            'fuente_origen' => 'Fuente mensual',
            'medio_origen' => 'Medio mensual',
            'campaign_acquired' => 'Campaña adquirida',
            'acquired_id' => 'id-anterior',
            'content_acquired' => 'content-legacy-nuevo',
            'acquired_source_legacy' => 'legacy-source-nuevo',
            'acquired_medium_legacy' => 'legacy-medium-anterior',
            'utm_campaign_new' => 'campaign-new-existente',
            'utm_id_new' => 'id-new-nuevo',
            'utm_source_new' => 'source-nuevo',
            'utm_medium_new' => 'medium-new-anterior',
            'utm_content_new' => 'content-new-nuevo',
            'record_type_name' => 'Venta',
            'resolved_portal' => 'Portal mensual',
        ]);
        $this->assertSame(
            ['scope' => 'monthly', 'complete' => true],
            SalesforceLead::query()->where('salesforce_id', '00Q-shared')->firstOrFail()->raw_payload,
        );
        $resolution = SalesforceLead::query()->where('salesforce_id', '00Q-shared')->firstOrFail()->field_resolution;
        $this->assertSame(['effective_value' => 'Meta'], $resolution['source']);
        $this->assertSame(['effective_value' => 'WhatsApp'], $resolution['channel']);
        $this->assertSame(['effective_value' => 'Paid Social'], $resolution['medium']);
        $this->assertSame(['effective_value' => 'Alcobendas'], $resolution['delegation']);
        $this->assertUtmResolution($resolution['utm_campaign'], 'campaign-new-existente', 'utm_campaign__c', false, true);
        $this->assertUtmResolution($resolution['utm_id'], 'id-new-nuevo', 'utm_id__c', false, true);
        $this->assertUtmResolution($resolution['utm_source'], 'source-nuevo', 'utm_source__c', false, true);
        $this->assertUtmResolution($resolution['utm_medium'], 'medium-new-anterior', 'utm_medium__c', false, true);
        $this->assertUtmResolution($resolution['utm_content'], 'content-new-nuevo', 'utm_content__c', false, true);
        $this->assertDatabaseCount('salesforce_leads', 1);
    }

    public function test_sync_completa_resoluciones_utm_sobre_json_null_parcial_o_invalido(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-null-resolution',
            'created_date' => '2026-05-10 10:00:00',
            'campaign_acquired' => 'Legacy existente',
            'field_resolution' => null,
        ]);
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-partial-resolution',
            'created_date' => '2026-05-10 10:00:00',
            'field_resolution' => [
                'source' => ['effective_value' => 'Meta'],
                'channel' => ['effective_value' => 'Chatbot'],
            ],
        ]);
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-invalid-resolution',
            'created_date' => '2026-05-10 10:00:00',
        ]);
        DB::table('salesforce_leads')
            ->where('salesforce_id', '00Q-invalid-resolution')
            ->update(['field_resolution' => 'invalid-json']);

        $client = $this->createMock(SalesforceClient::class);
        $client->expects($this->once())->method('query')->willReturn([
            $this->record('00Q-null-resolution', ['Campa_a_Adquirida__c' => 'Legacy entrante']),
            $this->record('00Q-partial-resolution', [
                'Id_Adquirido__c' => 'legacy-id',
                'utm_source__c' => 'new-source',
            ]),
            $this->record('00Q-invalid-resolution', ['Contenido_Adquirido__c' => 'legacy-content']),
        ]);

        (new CampaignLeadSyncService($client))->sync(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
        );

        $nullResolution = SalesforceLead::query()
            ->where('salesforce_id', '00Q-null-resolution')
            ->firstOrFail()
            ->field_resolution;
        $this->assertSame(
            ['utm_campaign', 'utm_id', 'utm_source', 'utm_medium', 'utm_content'],
            array_keys($nullResolution),
        );
        $this->assertUtmResolution($nullResolution['utm_campaign'], 'Legacy entrante', 'Campa_a_Adquirida__c', true, false);
        $this->assertUtmResolution($nullResolution['utm_id'], null, null, false, false);

        $partialResolution = SalesforceLead::query()
            ->where('salesforce_id', '00Q-partial-resolution')
            ->firstOrFail()
            ->field_resolution;
        $this->assertSame(['effective_value' => 'Meta'], $partialResolution['source']);
        $this->assertSame(['effective_value' => 'Chatbot'], $partialResolution['channel']);
        $this->assertArrayNotHasKey('medium', $partialResolution);
        $this->assertArrayNotHasKey('delegation', $partialResolution);
        $this->assertUtmResolution($partialResolution['utm_id'], 'legacy-id', 'Id_Adquirido__c', true, false);
        $this->assertUtmResolution($partialResolution['utm_source'], 'new-source', 'utm_source__c', false, false);

        $invalidResolution = SalesforceLead::query()
            ->where('salesforce_id', '00Q-invalid-resolution')
            ->firstOrFail()
            ->field_resolution;
        $this->assertSame(
            ['utm_campaign', 'utm_id', 'utm_source', 'utm_medium', 'utm_content'],
            array_keys($invalidResolution),
        );
        $this->assertUtmResolution($invalidResolution['utm_content'], 'legacy-content', 'Contenido_Adquirido__c', true, false);
    }

    public function test_sync_repetido_es_idempotente_y_conserva_nuevos_raw_en_ambas_tablas(): void
    {
        $record = $this->record('00Q-idempotent', [
            'Campa_a_Adquirida__c' => 'Legacy campaign',
            'Fuente_Adquirida__c' => 'Legacy acquired source',
            'Medio_Adquirido__c' => 'Legacy acquired medium',
            'utm_campaign__c' => 'New campaign',
            'utm_id__c' => 'new-id',
            'utm_source__c' => 'new-source',
            'utm_medium__c' => 'new-medium',
            'utm_content__c' => 'new-content',
        ]);
        $client = $this->createMock(SalesforceClient::class);
        $client->expects($this->exactly(2))->method('query')->willReturn([$record]);
        $service = new CampaignLeadSyncService($client);
        $start = CarbonImmutable::parse('2026-05-01');
        $end = CarbonImmutable::parse('2026-06-01');

        $service->sync($start, $end);
        $firstGeneralResolution = SalesforceLead::query()
            ->where('salesforce_id', '00Q-idempotent')
            ->firstOrFail()
            ->field_resolution;
        $service->sync($start, $end);

        $this->assertDatabaseCount('campaign_salesforce_leads', 1);
        $this->assertDatabaseCount('salesforce_leads', 1);
        $this->assertDatabaseHas('campaign_salesforce_leads', [
            'salesforce_id' => '00Q-idempotent',
            'campaign_acquired' => 'Legacy campaign',
            'acquired_source_legacy' => 'Legacy acquired source',
            'acquired_medium_legacy' => 'Legacy acquired medium',
            'utm_campaign_new' => 'New campaign',
            'utm_id_new' => 'new-id',
            'utm_source_new' => 'new-source',
            'utm_medium_new' => 'new-medium',
            'utm_content_new' => 'new-content',
        ]);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-idempotent',
            'utm_campaign_new' => 'New campaign',
        ]);
        $generalResolution = SalesforceLead::query()
            ->where('salesforce_id', '00Q-idempotent')
            ->firstOrFail()
            ->field_resolution;
        $campaignResolution = CampaignSalesforceLead::query()
            ->where('salesforce_id', '00Q-idempotent')
            ->firstOrFail()
            ->field_resolution;
        $this->assertSame($firstGeneralResolution, $generalResolution);
        $this->assertSame($campaignResolution, $generalResolution);
    }

    private function record(string $id, array $overrides): array
    {
        return array_replace([
            'Id' => $id,
            'CreatedDate' => '2026-05-10T10:00:00.000+0000',
            'Name' => "Lead {$id}",
            'Status' => 'Potencial',
            'OwnerId' => '005-owner',
            'Owner' => ['Name' => 'Owner'],
            'Campa_a_Adquirida__c' => null,
            'Id_Adquirido__c' => null,
            'Contenido_Adquirido__c' => null,
            'LEA_SEL_Fuente_Origen__c' => null,
            'LEA_SEL_Medio_Origen__c' => null,
            'Fuente_Adquirida__c' => null,
            'Medio_Adquirido__c' => null,
            'utm_campaign__c' => null,
            'utm_id__c' => null,
            'utm_source__c' => null,
            'utm_medium__c' => null,
            'utm_content__c' => null,
        ], $overrides);
    }

    private function assertUtmResolution(
        array $resolution,
        ?string $effectiveValue,
        ?string $sourceField,
        bool $usedFallback,
        bool $conflict,
    ): void {
        $this->assertSame($effectiveValue, $resolution['effective_value']);
        $this->assertSame($sourceField, $resolution['source_field']);
        $this->assertSame($usedFallback, $resolution['used_fallback']);
        $this->assertSame($conflict, $resolution['conflict']);
    }
}
