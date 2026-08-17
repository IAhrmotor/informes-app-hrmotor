<?php

namespace Tests\Unit;

use App\Services\SeoAnalytics\BrandVariantParser;
use PHPUnit\Framework\TestCase;

class SeoAnalyticsConfigurationTest extends TestCase
{
    public function test_brand_variants_are_trimmed_deduplicated_and_empty_values_are_removed(): void
    {
        $this->assertSame(
            ['hr motor', 'HRMotor', 'hr-motor', 'hrmotor.com', 'HŘ Motor'],
            BrandVariantParser::parse(' hr motor, HRMotor, ,hrmotor,hr-motor,hrmotor.com,HŘ Motor ')
        );
        $this->assertSame([], BrandVariantParser::parse(' , , '));
    }
}
