<?php

namespace Tests\Unit;

use App\Services\Reports\Leads\LeadDelegationNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadDelegationNormalizerTest extends TestCase
{
    #[DataProvider('mappedDelegations')]
    public function test_normaliza_alias_a_delegacion_y_zona(string $raw, string $delegation, string $zone): void
    {
        $result = app(LeadDelegationNormalizer::class)->normalize($raw);

        $this->assertSame($delegation, $result['delegation']);
        $this->assertSame($zone, $result['zone']);
        $this->assertTrue($result['is_classified']);
        $this->assertNull($result['raw_unmapped']);
    }

    #[DataProvider('unmappedDelegations')]
    public function test_valores_no_mapeados_van_a_sin_clasificar(?string $raw): void
    {
        $result = app(LeadDelegationNormalizer::class)->normalize($raw);

        $this->assertSame('Sin clasificar', $result['delegation']);
        $this->assertSame('Sin clasificar', $result['zone']);
        $this->assertFalse($result['is_classified']);
    }

    public static function mappedDelegations(): array
    {
        return [
            'torrejon' => ['HR MOTOR TORREJON', 'Torrejón', 'Madrid'],
            'torrejon ardoz' => ['Torrejón de Ardoz', 'Torrejón', 'Madrid'],
            'madrid email' => ['leadsmadrid@hrmotor.com', 'Madrid General', 'Madrid'],
            'rivas' => ['Rivas-Vaciamadrid', 'Rivas', 'Madrid'],
            'alcobendas' => ['Alcobendas', 'Alcobendas', 'Madrid'],
            'sant boi' => ['Sant Boi de Llobregat', 'Sant Boi', 'Barcelona'],
            'llica' => ['Llica de Vall', 'Lliçà de Vall', 'Barcelona'],
            'barcelona email' => ['leadsbarcelona@hrmotor.com', 'Barcelona General', 'Barcelona'],
            'sant boi email' => ['leadssantboi@hrmotor.com', 'Barcelona General', 'Barcelona'],
            'sedavi' => ['Valencia Sedavi', 'Sedaví', 'Valencia'],
            'paterna' => ['Valencia Paterna', 'Paterna', 'Valencia'],
            'valencia email' => ['leadsvalencia@hrmotor.com', 'Valencia General', 'Valencia'],
            'villareal' => ['Villareal', 'Villarreal/Almassora', 'Castellón'],
            'castellon email' => ['leadscastellon@hrmotor.com', 'Castellón General', 'Castellón'],
            'sevilla centro' => ['Sevilla Centro', 'Sevilla', 'Sevilla'],
            'alcala' => ['Alcalá de Guadaíra', 'Alcalá de Guadaira', 'Sevilla'],
            'sevilla email' => ['leadssevilla@hrmotor.com', 'Sevilla General', 'Sevilla'],
            'tudela' => ['Tudela', 'Fontellas', 'Navarra'],
            'tudela email' => ['leadstudela@hrmotor.com', 'Navarra General', 'Navarra'],
            'malaga centro' => ['Malaga Centro', 'Málaga Centro', 'Málaga'],
            'gasset email' => ['leads.gasset@hrmotor.com', 'Málaga General', 'Málaga'],
            'gijon' => ['Gijon', 'Gijón', 'Asturias'],
            'elche email' => ['leadselche@hrmotor.com', 'Elche', 'Alicante'],
            'coruna email' => ['leadsacoruna@hrmotor.com', 'A Coruña', 'A Coruña'],
            'guipuzcoa email' => ['leadsguipuzcoa@hrmotor.com', 'Bilbao', 'Bilbao'],
            'san sebastian' => ['San Sebastian', 'San Sebastián', 'San Sebastián'],
        ];
    }

    public static function unmappedDelegations(): array
    {
        return [
            'web alicante' => ['Web Alicante'],
            'llamada directa' => ['Llamada directa'],
            'empty' => [''],
            'null' => [null],
        ];
    }
}
