<?php

namespace Tests\Unit;

use App\Services\Reports\Calls\CallLeadPortalResolver;
use App\Services\Reports\Calls\CallPortalNormalizer;
use App\Services\Salesforce\SalesforceLeadFieldResolver;
use Tests\TestCase;

class CallLeadPortalResolverTest extends TestCase
{
    private CallLeadPortalResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CallLeadPortalResolver(new CallPortalNormalizer, new SalesforceLeadFieldResolver);
    }

    public function test_new_source_wins_without_changing_legacy_operational_portal(): void
    {
        $result = $this->resolver->resolve('3CX', [
            'Fuente_origen__c' => 'Coches.net',
            'Portal_Text__c' => 'Google Maps',
            'LEA_SEL_Fuente_Origen__c' => 'Wallapop',
            'Fuente_Nuevo__c' => 'Web',
        ]);

        $this->assertSame('Google Maps', $result['operational']['portal']);
        $this->assertSame('Coches.net', $result['visible']['portal']);
        $this->assertSame('Fuente_origen__c', $result['debug']['effective_source_field']);
        $this->assertTrue($result['debug']['conflict']);
    }

    public function test_blank_new_source_preserves_exact_call_legacy_order_and_placeholder_does_not_fallback(): void
    {
        $legacy = $this->resolver->resolve('3CX', [
            'Fuente_origen__c' => '   ',
            'Portal_Text__c' => 'Google Maps',
            'LEA_SEL_Fuente_Origen__c' => 'Coches.net',
        ]);
        $placeholder = $this->resolver->resolve('3CX', [
            'Fuente_origen__c' => 'Sin clasificar',
            'Portal_Text__c' => 'Google Maps',
        ]);

        $this->assertSame('Google Maps', $legacy['visible']['portal']);
        $this->assertSame('Portal_Text__c', $legacy['debug']['effective_source_field']);
        $this->assertTrue($legacy['debug']['used_fallback']);
        $this->assertSame(CallPortalNormalizer::UNCLASSIFIED, $placeholder['visible']['portal']);
        $this->assertSame('Fuente_origen__c', $placeholder['debug']['effective_source_field']);
    }
}
