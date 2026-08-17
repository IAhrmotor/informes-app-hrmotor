<?php

namespace Tests\Unit;

use App\Services\SeoAnalytics\SalesforceLeadMediumFieldResolver;
use PHPUnit\Framework\TestCase;

class SalesforceLeadMediumFieldResolverTest extends TestCase
{
    public function test_it_verifies_the_only_medium_field_with_exact_organic_value(): void
    {
        $result = (new SalesforceLeadMediumFieldResolver)->resolve(['fields' => [
            $this->field('LEA_SEL_Medio_Origen__c', 'Medio de origen', ['Orgánico', 'Pago']),
            $this->field('Medio_Nuevo__c', 'Medio', ['Formulario']),
            $this->field('Unrelated__c', 'Otro campo', ['Orgánico']),
        ]]);

        $this->assertSame('verified', $result['status']);
        $this->assertSame('LEA_SEL_Medio_Origen__c', $result['verified_field']);
        $this->assertCount(2, $result['candidates']);
        $this->assertTrue($result['candidates'][0]['is_picklist']);
        $this->assertTrue($result['candidates'][0]['has_organic']);
    }

    public function test_it_reports_absence_and_ambiguity_without_selecting_a_field(): void
    {
        $resolver = new SalesforceLeadMediumFieldResolver;

        $missing = $resolver->resolve(['fields' => [
            $this->field('LEA_SEL_Medio_Origen__c', 'Medio de origen', ['Pago']),
        ]]);
        $ambiguous = $resolver->resolve(['fields' => [
            $this->field('LEA_SEL_Medio_Origen__c', 'Medio de origen', ['Orgánico']),
            $this->field('Medio_Nuevo__c', 'Medio', ['Orgánico']),
        ]]);

        $this->assertSame('not_found', $missing['status']);
        $this->assertNull($missing['verified_field']);
        $this->assertSame('ambiguous', $ambiguous['status']);
        $this->assertNull($ambiguous['verified_field']);
    }

    /** @param array<int, string> $values */
    private function field(string $name, string $label, array $values): array
    {
        return [
            'name' => $name,
            'label' => $label,
            'type' => 'picklist',
            'picklistValues' => array_map(
                fn (string $value): array => ['active' => true, 'value' => $value],
                $values
            ),
        ];
    }
}
