<?php

namespace Tests\Unit;

use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Salesforce\SalesforceLeadFieldResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesforceLeadFieldResolverTest extends TestCase
{
    #[DataProvider('emptyValues')]
    public function test_null_empty_and_whitespace_use_the_legacy_fallback(mixed $empty): void
    {
        $result = (new SalesforceLeadFieldResolver)->resolve(
            $empty,
            'Campo_nuevo__c',
            ' Legacy ',
            'Campo_Legacy__c',
        );

        $this->assertSame('Legacy', $result['effective_value']);
        $this->assertSame('Campo_Legacy__c', $result['source_field']);
        $this->assertTrue($result['used_fallback']);
        $this->assertFalse($result['conflict']);
        $this->assertSame($empty, $result['new_raw']);
        $this->assertSame(' Legacy ', $result['legacy_raw']);
    }

    public static function emptyValues(): array
    {
        return [[null], [''], [' '], ['   ']];
    }

    #[DataProvider('validPlaceholders')]
    public function test_non_empty_placeholders_are_valid_new_values(string $value): void
    {
        $result = (new SalesforceLeadFieldResolver)->resolve(
            " {$value} ",
            'Campo_nuevo__c',
            'Legacy',
            'Campo_Legacy__c',
        );

        $this->assertSame($value, $result['effective_value']);
        $this->assertSame('Campo_nuevo__c', $result['source_field']);
        $this->assertFalse($result['used_fallback']);
        $this->assertTrue($result['conflict']);
    }

    public static function validPlaceholders(): array
    {
        return [
            ['Sin clasificar'],
            ['Sin informar'],
            ['No identificado'],
            ['Desconocida'],
        ];
    }

    public function test_equal_values_after_trim_do_not_conflict_and_new_wins(): void
    {
        $result = (new SalesforceLeadFieldResolver)->resolve(
            ' Meta ',
            'Fuente_origen__c',
            'Meta',
            'Portal_Text__c',
        );

        $this->assertSame('Meta', $result['effective_value']);
        $this->assertSame('Fuente_origen__c', $result['source_field']);
        $this->assertFalse($result['used_fallback']);
        $this->assertFalse($result['conflict']);
    }

    public function test_both_empty_resolve_to_null_without_a_source(): void
    {
        $result = (new SalesforceLeadFieldResolver)->resolve(' ', 'Nuevo__c', null, 'Legacy__c');

        $this->assertNull($result['effective_value']);
        $this->assertNull($result['source_field']);
        $this->assertFalse($result['used_fallback']);
        $this->assertFalse($result['conflict']);
    }

    #[DataProvider('newChannels')]
    public function test_new_channel_is_preserved_without_reducing_it_to_the_legacy_binary(string $raw, string $expected): void
    {
        $result = (new SalesforceLeadFieldResolver)->resolve(
            $raw,
            'Canal__c',
            'Formulario',
            'Medio_Nuevo__c',
        );

        $this->assertSame($expected, $result['effective_value']);
        $this->assertSame('Canal__c', $result['source_field']);
        $this->assertFalse($result['used_fallback']);
    }

    public static function newChannels(): array
    {
        return [
            ['Llamada', 'Llamada'],
            ['Formulario', 'Formulario'],
            ['WhatsApp', 'WhatsApp'],
            ['Chatbot', 'Chatbot'],
            [' Sin clasificar ', 'Sin clasificar'],
        ];
    }

    public function test_new_delegation_wins_over_contextual_exposition_fallback_and_remains_normalizable(): void
    {
        $resolved = (new SalesforceLeadFieldResolver)->resolve(
            ' Alcalá de Guadaíra ',
            'Delegacion_procedencia__c',
            'HR MOTOR MURCIA',
            'Owner.USR_SEL_Delegacion__c',
        );
        $normalized = app(LeadDelegationNormalizer::class)->normalize($resolved['effective_value']);

        $this->assertSame('Alcalá de Guadaíra', $resolved['effective_value']);
        $this->assertSame('Delegacion_procedencia__c', $resolved['source_field']);
        $this->assertTrue($resolved['conflict']);
        $this->assertSame('Alcalá de Guadaira', $normalized['delegation']);
    }

    public function test_resolves_every_dimension_independently_and_utm_id_never_names_campaign(): void
    {
        $result = (new SalesforceLeadFieldResolver)->resolveLead([
            'Fuente_origen__c' => 'Meta',
            'Canal__c' => ' ',
            'Medio_origen__c' => 'Paid Social',
            'Delegacion_procedencia__c' => '',
            'LEA_SEL_Medio_Origen__c' => 'Legacy medium',
            'utm_campaign__c' => 'New campaign',
            'Campa_a_Adquirida__c' => 'Legacy campaign',
            'utm_id__c' => ' ',
            'Id_Adquirido__c' => 'legacy-id',
            'utm_source__c' => 'new-source',
            'Fuente_Adquirida__c' => 'legacy-source',
            'utm_medium__c' => null,
            'Medio_Adquirido__c' => 'legacy-medium',
            'utm_content__c' => 'new-content',
            'Contenido_Adquirido__c' => 'legacy-content',
        ], [
            'value' => 'Coches.net',
            'field' => 'Portal_Text__c',
        ], [
            'value' => 'Llamada',
            'field' => 'Medio_Nuevo__c',
        ], [
            'value' => 'Alcobendas',
            'field' => 'Delegacion_Encargada_Bueno__c',
        ]);

        $this->assertSame('Meta', $result['source']['effective_value']);
        $this->assertSame('Llamada', $result['channel']['effective_value']);
        $this->assertTrue($result['channel']['used_fallback']);
        $this->assertSame('Paid Social', $result['medium']['effective_value']);
        $this->assertSame('Alcobendas', $result['delegation']['effective_value']);
        $this->assertSame('New campaign', $result['utm_campaign']['effective_value']);
        $this->assertSame('utm_campaign__c', $result['utm_campaign']['source_field']);
        $this->assertSame('legacy-id', $result['utm_id']['effective_value']);
        $this->assertSame('Id_Adquirido__c', $result['utm_id']['source_field']);
        $this->assertSame('new-source', $result['utm_source']['effective_value']);
        $this->assertSame('legacy-medium', $result['utm_medium']['effective_value']);
        $this->assertSame('new-content', $result['utm_content']['effective_value']);
        $this->assertNotSame($result['utm_id']['effective_value'], $result['utm_campaign']['effective_value']);
    }
}
