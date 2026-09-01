<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Services\Campaigns\CampaignLeadSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'record_type_normalized' => 'venta',
            'fuente_origen' => 'Fuente mensual',
            'medio_origen' => 'Medio mensual',
            'campaign_acquired' => 'Campaña anterior',
            'acquired_id' => 'id-anterior',
            'utm_source_new' => 'source-anterior',
            'resolved_portal' => 'Portal mensual',
            'portal_resolution_source' => 'Portal_Text__c',
            'raw_payload' => ['scope' => 'monthly', 'complete' => true],
        ]);

        $client = $this->createMock(SalesforceClient::class);
        $client->expects($this->once())->method('query')->willReturn([
            $this->record('00Q-shared', [
                'Name' => 'Nombre campaña',
                'Campa_a_Adquirida__c' => 'Campaña adquirida',
                'Id_Adquirido__c' => '   ',
                'utm_source__c' => '',
                'utm_medium__c' => 'paid-social',
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
            'fuente_origen' => 'Fuente mensual',
            'medio_origen' => 'Medio mensual',
            'campaign_acquired' => 'Campaña adquirida',
            'acquired_id' => 'id-anterior',
            'utm_source_new' => 'source-anterior',
            'utm_medium_new' => 'paid-social',
            'record_type_name' => 'Venta',
            'resolved_portal' => 'Portal mensual',
        ]);
        $this->assertSame(
            ['scope' => 'monthly', 'complete' => true],
            SalesforceLead::query()->where('salesforce_id', '00Q-shared')->firstOrFail()->raw_payload,
        );
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
}
