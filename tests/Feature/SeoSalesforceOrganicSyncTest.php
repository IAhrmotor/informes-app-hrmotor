<?php

namespace Tests\Feature;

use App\Models\SeoSalesforceOrganicDailyMetric;
use App\Services\Salesforce\SalesforceClient;
use App\Services\SeoAnalytics\SalesforceOrganicLeadSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SeoSalesforceOrganicSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_records_in_madrid_days_preserves_zero_days_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 10:00:00 Europe/Madrid');
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('queryPages')->once()->withArgs(function (string $soql): bool {
            $this->assertStringContainsString('Medio_origen__c', $soql);
            $this->assertStringContainsString("= 'Orgánico'", $soql);
            $this->assertStringNotContainsString('LEA_SEL_Medio_Origen__c', $soql);

            return true;
        })->andReturn((function (): \Generator {
            yield [
                ['Id' => 'synthetic-1', 'CreatedDate' => '2026-08-16T22:30:00Z'],
                ['Id' => 'synthetic-2', 'CreatedDate' => '2026-08-16T22:30:00Z'],
            ];
        })());

        $result = (new SalesforceOrganicLeadSyncService($client))->sync(2);

        $this->assertSame(2, $result['stats']['queried']);
        $this->assertDatabaseHas('seo_salesforce_organic_daily_metrics', ['data_date' => '2026-08-17', 'lead_count' => 2]);
        $this->assertDatabaseHas('seo_salesforce_organic_daily_metrics', ['data_date' => '2026-08-16', 'lead_count' => 0]);
        $this->assertDatabaseMissing('seo_salesforce_organic_daily_metrics', ['data_date' => '2026-08-18']);

        $emptyClient = Mockery::mock(SalesforceClient::class);
        $emptyClient->shouldReceive('queryPages')->once()->andReturn((function (): \Generator {
            if (false) {
                yield [];
            }
        })());
        (new SalesforceOrganicLeadSyncService($emptyClient))->sync(2);
        $this->assertSame(2, SeoSalesforceOrganicDailyMetric::query()->count());
        $this->assertDatabaseHas('seo_salesforce_organic_daily_metrics', ['data_date' => '2026-08-17', 'lead_count' => 0]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
