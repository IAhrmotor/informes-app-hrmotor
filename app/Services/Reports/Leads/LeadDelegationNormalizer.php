<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Str;

class LeadDelegationNormalizer
{
    public const UNCLASSIFIED = 'Sin clasificar';

    private ?array $aliasMap = null;

    public function normalize(?string $raw): array
    {
        $raw = $this->clean($raw);

        if ($raw === null) {
            return $this->unclassified(null);
        }

        $match = $this->aliasMap()[$this->key($raw)] ?? null;

        if ($match === null) {
            return [
                'raw' => $raw,
                'delegation' => self::UNCLASSIFIED,
                'zone' => self::UNCLASSIFIED,
                'is_classified' => false,
                'raw_unmapped' => $raw,
            ];
        }

        return [
            'raw' => $raw,
            'delegation' => $match['delegation'],
            'zone' => $match['zone'],
            'is_classified' => true,
            'raw_unmapped' => null,
        ];
    }

    public function sortLabels(iterable $labels): array
    {
        return collect($labels)
            ->filter()
            ->unique()
            ->sortBy(fn (string $label) => $label === self::UNCLASSIFIED ? 'zzzzzz' : Str::ascii($label))
            ->values()
            ->all();
    }

    public function knownZones(): array
    {
        return $this->sortLabels(collect($this->aliasMap())->pluck('zone')->all());
    }

    private function aliasMap(): array
    {
        if ($this->aliasMap !== null) {
            return $this->aliasMap;
        }

        $map = [];

        $this->add($map, 'Madrid', 'Torrejón', [
            'HR MOTOR TORREJON', 'Torrejón', 'Torrejon', 'Torrejón de Ardoz', 'leadstorrejon@hrmotor.com',
        ]);
        $this->add($map, 'Madrid', 'Villalba', [
            'HR MOTOR VILLALBA', 'HR MOTOR COLLADO VILLABA', 'HR MOTOR COLLADO VILLALBA', 'Villalba',
            'Collado Villalba', 'leadsvillalba@hrmotor.com',
        ]);
        $this->add($map, 'Madrid', 'Rivas', [
            'HR MOTOR RIVAS-VACIA MADRID', 'HR MOTOR RIVAS', 'Rivas', 'Rivas-Vaciamadrid',
            'Rivas vacia madrid', 'leadsrivas@hrmotor.com',
        ]);
        $this->add($map, 'Madrid', 'Alcobendas', [
            'HR MOTOR ALCOBENDAS', 'Alcobendas', 'leadsalcobendas@hrmotor.com',
        ]);
        $this->add($map, 'Madrid', 'Madrid General', [
            'Madrid', 'HR MOTOR MADRID', 'Zona Madrid', 'leadsmadrid@hrmotor.com', 'leadsmadridgeneral@hrmotor.com',
        ]);

        $this->add($map, 'Barcelona', 'Sant Boi', [
            'HR MOTOR SANT BOI DE LLOBREGAT', 'HR MOTOR SAN BOI', 'HR MOTOR SANT BOI', 'Sant Boi',
            'San Boi', 'Sant Boi de Llobregat', 'sant boi de llobregat',
        ]);
        $this->add($map, 'Barcelona', 'Badalona', ['HR MOTOR BADALONA', 'Badalona', 'leadsbadalona@hrmotor.com']);
        $this->add($map, 'Barcelona', 'Lliçà de Vall', [
            'HR MOTOR LLIÇÀ DE VALL', 'HR MOTOR LLIÇA', 'Lliçà de Vall', 'Lliça de Vall',
            'Llica de Vall', 'LLIÇÀ DE VALL',
        ]);
        $this->add($map, 'Barcelona', 'Manresa', ['HR MOTOR MANRESA', 'Manresa', 'leadsmanresa@hrmotor.com']);
        $this->add($map, 'Barcelona', 'Lleida', ['HR MOTOR LLEIDA', 'Lleida', 'Lerida', 'leadslleida@hrmotor.com']);
        $this->add($map, 'Barcelona', 'Girona', ['HR MOTOR GIRONA', 'Girona', 'leadsgirona@hrmotor.com']);
        $this->add($map, 'Barcelona', 'Barcelona General', [
            'Barcelona', 'Zona Barcelona', 'leadsbarcelona@hrmotor.com', 'Hr Motor Barcelona',
            'HR MOTOR BARCELONA', 'leadssantboi@hrmotor.com', 'leadsllica@hrmotor.com',
        ]);

        $this->add($map, 'Valencia', 'Valencia', ['HR MOTOR VALENCIA', 'HR MOTOR CAMPA VALENCIA', 'Valencia']);
        $this->add($map, 'Valencia', 'Valencia General', ['Valencia general', 'leadsvalencia@hrmotor.com']);
        $this->add($map, 'Valencia', 'Paterna', ['HR MOTOR PATERNA', 'Paterna', 'Valencia Paterna', 'leadspaterna@hrmotor.com']);
        $this->add($map, 'Valencia', 'Sedaví', ['HR MOTOR SEDAVI', 'Sedaví', 'Sedavi', 'Valencia Sedavi', 'leadssedavi@hrmotor.com']);

        $this->add($map, 'Castellón', 'Castellón', ['HR MOTOR CASTELLON', 'Castellón', 'Castellon']);
        $this->add($map, 'Castellón', 'Villarreal/Almassora', [
            'HR MOTOR VILLAREAL/ALMASSORA', 'HR MOTOR VILLARREAL/ALMASSORA', 'HR MOTOR ALMAZORAS',
            'Villarreal', 'Villareal', 'Almassora', 'leadsvillareal@hrmotor.com',
        ]);
        $this->add($map, 'Castellón', 'Castellón General', ['Castellón general', 'Castellon general', 'leadscastellon@hrmotor.com']);

        $this->add($map, 'Sevilla', 'Sevilla', ['HR MOTOR SEVILLA', 'Sevilla', 'Sevilla Centro', 'HR MOTOR SEVILLA CENTRO']);
        $this->add($map, 'Sevilla', 'Alcalá de Guadaira', [
            'HR MOTOR ALCALA DE GUADAIRA', 'Sevilla-Alcalá de Guadaira-HR Motor',
            'Alcalá de Guadaira', 'Alcala de Guadaira', 'Alcalá de Guadaíra', 'leadsalcala@hrmotor.com',
        ]);
        $this->add($map, 'Sevilla', 'Dos Hermanas', ['HR MOTOR DOS HERMANAS', 'Dos Hermanas', 'leadsdoshermanas@hrmotor.com']);
        $this->add($map, 'Sevilla', 'Sevilla General', ['Sevilla general', 'leadssevilla@hrmotor.com']);

        $this->add($map, 'Navarra', 'Fontellas', ['HR MOTOR FONTELLAS', 'HR MOTOR TUDELA', 'Fontellas', 'Tudela', 'leadsfontellas@hrmotor.com']);
        $this->add($map, 'Navarra', 'Pamplona', ['HR MOTOR PAMPLONA', 'Pamplona', 'leadspamplona@hrmotor.com']);
        $this->add($map, 'Navarra', 'Navarra General', ['Navarra', 'Zona Navarra', 'leadstudela@hrmotor.com']);

        $this->add($map, 'Málaga', 'Málaga', ['HR MOTOR MALAGA', 'Málaga', 'Malaga', 'leadsmalaga@hrmotor.com']);
        $this->add($map, 'Málaga', 'Málaga Centro', ['HR MOTOR MALAGA CENTRO', 'Málaga Centro', 'Malaga Centro']);
        $this->add($map, 'Málaga', 'Málaga General', [
            'Málaga general', 'Malaga general', 'Zona Málaga', 'Zona Malaga', 'leads.gasset@hrmotor.com',
            'leadsalmachar@hrmotor.com',
        ]);

        $this->add($map, 'Alicante', 'Alicante', ['HR MOTOR ALICANTE', 'Alicante', 'leadsalicante@hrmotor.com']);
        $this->add($map, 'Murcia', 'Murcia', ['HR MOTOR MURCIA', 'Murcia', 'leadsmurcia@hrmotor.com']);
        $this->add($map, 'Zaragoza', 'Zaragoza', ['HR MOTOR ZARAGOZA', 'Zaragoza', 'leadszaragoza@hrmotor.com']);
        $this->add($map, 'Asturias', 'Gijón', ['HR MOTOR GIJON', 'Gijón', 'Gijon', 'leadsgijon@hrmotor.com']);
        $this->add($map, 'Valladolid', 'Valladolid', ['HR MOTOR VALLADOLID', 'Valladolid', 'leadsvalladolid@hrmotor.com']);
        $this->add($map, 'Mallorca', 'Mallorca', ['HR MOTOR MALLORCA', 'Mallorca', 'leads.mallorca@hrmotor.com']);
        $this->add($map, 'Alicante', 'Elche', ['HR MOTOR ELCHE', 'Elche', 'leadselche@hrmotor.com']);
        $this->add($map, 'A Coruña', 'A Coruña', [
            'HR MOTOR A CORUÑA', 'HR A CORUÑA', 'HRMOTOR CORUÑA', 'A Coruña', 'Coruña',
            'A Coruna', 'Coruna', 'leadsacoruna@hrmotor.com',
        ]);
        $this->add($map, 'Alicante', 'Alcoy', ['HR MOTOR ALCOY', 'Alcoy', 'leadsalcoy@hrmotor.com']);
        $this->add($map, 'Bilbao', 'Bilbao', ['HR MOTOR BILBAO', 'Bilbao', 'leadsbilbao@hrmotor.com', 'leadsguipuzcoa@hrmotor.com']);
        $this->add($map, 'San Sebastián', 'San Sebastián', ['HR MOTOR SAN SEBASTIAN', 'San Sebastián', 'San Sebastian', 'leadsoiartzun@hrmotor.com']);

        return $this->aliasMap = $map;
    }

    private function add(array &$map, string $zone, string $delegation, array $aliases): void
    {
        foreach ($aliases as $alias) {
            $map[$this->key($alias)] = [
                'delegation' => $delegation,
                'zone' => $zone,
            ];
        }
    }

    private function unclassified(?string $raw): array
    {
        return [
            'raw' => $raw,
            'delegation' => self::UNCLASSIFIED,
            'zone' => self::UNCLASSIFIED,
            'is_classified' => false,
            'raw_unmapped' => $raw,
        ];
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function key(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '@')) {
            return Str::of($value)
                ->lower()
                ->replaceMatches('/\s+/', '')
                ->toString();
        }

        return Str::of($value)
            ->lower()
            ->ascii()
            ->replace(['.', ',', '_'], [' ', ' ', ' '])
            ->replace(['-', '/', '\\'], [' ', ' ', ' '])
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
