<?php

namespace Tests\Unit;

use App\Services\Reports\Leads\LeadClassificationResolver;
use Tests\TestCase;

class LeadClassificationResolverTest extends TestCase
{
    public function test_new_source_wins_for_call_and_form_and_keeps_conflict_auditable(): void
    {
        $resolver = new LeadClassificationResolver;

        foreach (['Llamada', 'Formulario'] as $legacyChannel) {
            $result = $resolver->resolve([
                'Medio_Nuevo__c' => $legacyChannel,
                'Fuente_origen__c' => ' Meta ',
                'Fuente_Nuevo__c' => 'Coches.net',
                'Portal_Text__c' => 'Web',
            ]);

            $this->assertSame('Meta', $result['portal']);
            $this->assertSame('Fuente_origen__c', $result['portal_resolution_source']);
            $this->assertTrue($result['fields']['source']['conflict']);
        }
    }

    public function test_blank_new_fields_preserve_legacy_priorities_and_new_placeholders_block_them(): void
    {
        $resolver = new LeadClassificationResolver;

        $legacy = $resolver->resolve([
            'Medio_Nuevo__c' => 'Llamada',
            'Fuente_origen__c' => '   ',
            'Fuente_Nuevo__c' => 'Google Maps',
            'Portal_Text__c' => 'Web',
        ]);
        $placeholder = $resolver->resolve([
            'Medio_Nuevo__c' => 'Llamada',
            'Fuente_origen__c' => 'Sin clasificar',
            'Fuente_Nuevo__c' => 'Google Maps',
        ]);

        $this->assertSame('Google Maps', $legacy['portal']);
        $this->assertSame('Fuente_Nuevo__c', $legacy['portal_resolution_source']);
        $this->assertTrue($legacy['fields']['source']['used_fallback']);
        $this->assertSame('Sin clasificar', $placeholder['portal']);
        $this->assertSame('Fuente_origen__c', $placeholder['portal_resolution_source']);
    }

    public function test_new_channel_and_medium_are_resolved_independently(): void
    {
        $result = (new LeadClassificationResolver)->resolve([
            'Canal__c' => 'Chatbot',
            'Medio_Nuevo__c' => 'Llamada',
            'Medio_origen__c' => 'Email',
            'LEA_SEL_Medio_Origen__c' => 'CPC',
        ]);

        $this->assertSame('Chatbot', $result['channel']);
        $this->assertSame('Canal__c', $result['fields']['channel']['source_field']);
        $this->assertSame('Email', $result['fields']['medium']['effective_value']);
        $this->assertSame('Medio_origen__c', $result['fields']['medium']['source_field']);
    }

    public function test_new_delegation_wins_over_the_frozen_legacy_chain(): void
    {
        $result = (new LeadClassificationResolver)->resolve([
            'Delegacion_procedencia__c' => 'Sin clasificar',
            'Delegacion_Encargada_Bueno__c' => 'HR MOTOR ZARAGOZA',
            'Delegacion_Encargada__c' => 'HR MOTOR VALENCIA',
            'Delegacion_Encargada_Text__c' => 'Madrid',
        ]);

        $this->assertSame('Sin clasificar', $result['fields']['delegation']['effective_value']);
        $this->assertSame('Delegacion_procedencia__c', $result['fields']['delegation']['source_field']);
        $this->assertTrue($result['fields']['delegation']['conflict']);
    }
}
