<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardPerformancePayloadTest extends TestCase
{
    public function test_dataset_usa_payload_cacheado_y_chunks_para_endpoints(): void
    {
        $dataset = file_get_contents(app_path('Services/Reports/Leads/SalesforceLeadDashboardDatasetService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Reports/Leads/LeadDashboardDataController.php'));

        $this->assertStringContainsString('Cache::remember', $dataset);
        $this->assertStringContainsString('CACHE_TTL_MINUTES = 10', $dataset);
        $this->assertStringContainsString('chunkById', $dataset);
        $this->assertStringContainsString('SalesforceLeadDashboardDatasetService', $controller);
        $this->assertStringNotContainsString('LeadNormalized', $controller);
        $this->assertStringNotContainsString('LeadRaw', $controller);
    }
}
