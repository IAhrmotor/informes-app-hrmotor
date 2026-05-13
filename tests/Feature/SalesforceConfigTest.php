<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesforceConfigTest extends TestCase
{
    public function test_salesforce_config_loads_auth_mode_and_api_version(): void
    {
        $config = require base_path('config/salesforce.php');

        $this->assertArrayHasKey('auth_mode', $config);
        $this->assertArrayHasKey('api_version', $config);
        $this->assertNotEmpty(config('salesforce.auth_mode'));
        $this->assertNotEmpty(config('salesforce.api_version'));
    }
}
