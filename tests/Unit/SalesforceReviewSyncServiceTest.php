<?php

namespace Tests\Unit;

use App\Models\SalesforceReview;
use App\Services\Reports\CommercialCommissions\Sync\SalesforceReviewSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceReviewSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_todas_las_resenas_y_respeta_owner_de_oportunidad(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct()
            {
            }

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [
                    [
                        'Id' => 'a01-review-1',
                        'CreatedDate' => '2026-06-21T10:00:00.000+0000',
                        'OwnerId' => '005-review-1',
                        'Owner' => ['Name' => 'Owner Review 1'],
                        'RES_BUS_Oportunidad__c' => '006-opp-1',
                        'RES_BUS_Oportunidad__r' => [
                            'Name' => 'Venta 1',
                            'OwnerId' => '005-commercial-1',
                            'Owner' => ['Name' => 'Comercial Uno'],
                            'RecordType' => ['Name' => 'Venta'],
                            'Fecha_firma_contrato__c' => '2026-06-20',
                        ],
                    ],
                    [
                        'Id' => 'a01-review-2',
                        'CreatedDate' => '2026-06-21T11:00:00.000+0000',
                        'OwnerId' => '005-review-2',
                        'Owner' => ['Name' => 'Owner Review 2'],
                        'RES_BUS_Oportunidad__c' => '006-opp-1',
                        'RES_BUS_Oportunidad__r' => [
                            'Name' => 'Venta 1',
                            'OwnerId' => '005-commercial-1',
                            'Owner' => ['Name' => 'Comercial Uno'],
                            'RecordType' => ['Name' => 'Venta'],
                            'Fecha_firma_contrato__c' => '2026-06-20',
                        ],
                    ],
                ];
            }
        };

        $service = new SalesforceReviewSyncService($client);
        $result = $service->sync(
            CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
        );

        $this->assertSame(2, $result['queried']);
        $this->assertSame(2, $result['saved']);
        $this->assertStringContainsString('FROM Resena__c', $result['soql']);
        $this->assertDatabaseCount('salesforce_reviews', 2);
        $this->assertDatabaseHas('salesforce_reviews', [
            'salesforce_id' => 'a01-review-1',
            'owner_id' => '005-review-1',
            'opportunity_salesforce_id' => '006-opp-1',
            'opportunity_owner_id' => '005-commercial-1',
            'opportunity_owner_name' => 'Comercial Uno',
        ]);
        $this->assertDatabaseHas('salesforce_reviews', [
            'salesforce_id' => 'a01-review-2',
            'opportunity_salesforce_id' => '006-opp-1',
            'opportunity_owner_id' => '005-commercial-1',
        ]);
    }

    public function test_sync_all_history_arranca_desde_2020(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct()
            {
            }

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [];
            }
        };

        $service = new SalesforceReviewSyncService($client);
        $service->syncAllHistory(CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'));

        $this->assertNotEmpty($client->queries);
        $this->assertStringContainsString('2020-01-01T00:00:00Z', $client->queries[0]);
    }
}
