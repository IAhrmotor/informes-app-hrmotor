<?php

namespace Tests\Unit;

use App\Services\SeoAnalytics\BrandQueryClassifier;
use RuntimeException;
use Tests\TestCase;

class SeoBrandQueryClassifierTest extends TestCase
{
    public function test_approved_variants_classify_brand_and_build_one_re2_compatible_regex(): void
    {
        config(['seo_analytics.brand_variants' => ['hr motor', 'hrmotor', 'hr-motor', 'hrmotor.com']]);
        $classifier = app(BrandQueryClassifier::class);

        foreach (['HR MOTOR', 'hr motor sevilla', 'hrmotor rivas', 'hr-motor', 'hrmotor.com'] as $query) {
            $this->assertSame('brand', $classifier->classify($query));
        }
        $this->assertSame('non_brand', $classifier->classify('comprar coche'));
        $this->assertSame('(?i)(?:hr motor|hrmotor|hr\-motor|hrmotor\.com)', $classifier->regex());
    }

    public function test_empty_variants_fail_explicitly(): void
    {
        config(['seo_analytics.brand_variants' => []]);
        $this->expectException(RuntimeException::class);
        app(BrandQueryClassifier::class)->regex();
    }
}
