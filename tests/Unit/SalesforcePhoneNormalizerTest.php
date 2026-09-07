<?php

namespace Tests\Unit;

use App\Services\Salesforce\SalesforcePhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalesforcePhoneNormalizerTest extends TestCase
{
    #[DataProvider('phones')]
    public function test_conserva_la_semantica_telefonica_historica(mixed $raw, ?string $expected): void
    {
        $this->assertSame($expected, (new SalesforcePhoneNormalizer)->normalize($raw));
    }

    public static function phones(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'standard' => ['612345678', '612345678'],
            'spanish-prefix' => ['34612345678', '612345678'],
            'plus-prefix' => ['+34 612 345 678', '612345678'],
            'spaces-two-digits' => ['612 34 56 78', '612345678'],
            'hyphens' => ['612-345-678', '612345678'],
            'dots' => ['612.345.678', '612345678'],
            'multiple-values' => ['612345678 / 699887766', '612345678699887766'],
            'residual-characters' => ['Tel: (612) ABC 345 678', '612345678'],
            'prefix-only-when-nine-follow' => ['341234', '341234'],
        ];
    }
}
