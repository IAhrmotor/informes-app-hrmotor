<?php

namespace Database\Seeders;

use App\Models\MasterCallDelegationMapping;
use App\Models\MasterDelegation;
use Illuminate\Database\Seeder;

class MasterCallDelegationMappingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = array_merge(
            $this->genericDelegationMappingsForPortals([
                'Web',
                'Google Maps',
                'Meta',
                'Chatbot',
            ]),
            $this->cochesNetHistoricalMappings(),
            $this->wallapopMappings(),
            $this->sumautoAutocasionMappings(),
            $this->cochesComMappings()
        );

        foreach ($rows as $row) {
            MasterCallDelegationMapping::updateOrCreate(
                [
                    'portal_original' => $row['portal_original'],
                    'received_value' => $row['received_value'],
                ],
                [
                    'type' => $row['type'] ?? null,
                    'delegation_name' => $row['delegation_name'] ?? null,
                    'commercial_group' => $row['commercial_group'] ?? null,
                    'status' => $row['status'] ?? 'active',
                    'valid_from' => $row['valid_from'] ?? null,
                    'valid_to' => $row['valid_to'] ?? null,
                ]
            );
        }
    }

    private function genericDelegationMappingsForPortals(array $portals): array
    {
        $delegations = MasterDelegation::query()
            ->where('is_active', true)
            ->orderBy('delegation_name')
            ->get();

        $rows = [];

        foreach ($portals as $portal) {
            foreach ($delegations as $delegation) {
                $rows[] = $this->row(
                    portalOriginal: $portal,
                    receivedValue: $this->friendlyDelegationValue($delegation->delegation_name),
                    type: 'Delegación',
                    delegationName: $delegation->delegation_name,
                    commercialGroup: $delegation->commercial_group
                );

                $rows[] = $this->row(
                    portalOriginal: $portal,
                    receivedValue: $delegation->delegation_name,
                    type: 'Delegación',
                    delegationName: $delegation->delegation_name,
                    commercialGroup: $delegation->commercial_group
                );
            }
        }

        return $rows;
    }

    private function cochesNetHistoricalMappings(): array
    {
        return [
            $this->row('Coches.net', 'Madrid', 'Grupo', null, 'Madrid'),
            $this->row('Coches.net', 'Barcelona', 'Grupo', null, 'Barcelona'),
            $this->row('Coches.net', 'Navarra', 'Grupo', null, 'Navarra'),
            $this->row('Coches.net', 'Castellón', 'Grupo', null, 'Castellón'),
            $this->row('Coches.net', 'Málaga', 'Grupo', null, 'Málaga'),

            $this->row('Coches.net', 'Alcobendas', 'Delegación', 'HR MOTOR ALCOBENDAS', 'Madrid'),
            $this->row('Coches.net', 'Rivas', 'Delegación', 'HR MOTOR RIVAS-VACIA MADRID', 'Madrid'),
            $this->row('Coches.net', 'Rivas-Vaciamadrid', 'Delegación', 'HR MOTOR RIVAS-VACIA MADRID', 'Madrid'),
            $this->row('Coches.net', 'Torrejón', 'Delegación', 'HR MOTOR TORREJON', 'Madrid'),
            $this->row('Coches.net', 'Villalba', 'Delegación', 'HR MOTOR VILLALBA', 'Madrid'),
            $this->row('Coches.net', 'Sant Boi', 'Delegación', 'HR MOTOR SANT BOI DE LLOBREGAT', 'Barcelona'),
            $this->row('Coches.net', 'Badalona', 'Delegación', 'HR MOTOR BADALONA', 'Barcelona'),
            $this->row('Coches.net', 'Lliçà', 'Delegación', 'HR MOTOR LLIÇÀ DE VALL', 'Barcelona'),
            $this->row('Coches.net', 'Manresa', 'Delegación', 'HR MOTOR MANRESA', 'Barcelona'),
            $this->row('Coches.net', 'Zaragoza', 'Delegación', 'HR MOTOR ZARAGOZA', 'Zaragoza'),
            $this->row('Coches.net', 'Valencia', 'Delegación', 'HR MOTOR VALENCIA', 'Valencia'),
            $this->row('Coches.net', 'Paterna', 'Delegación', 'HR MOTOR PATERNA', 'Valencia'),
            $this->row('Coches.net', 'Sedaví', 'Delegación', 'HR MOTOR SEDAVI', 'Valencia'),
            $this->row('Coches.net', 'Sevilla', 'Delegación', 'HR MOTOR SEVILLA', 'Sevilla'),
            $this->row('Coches.net', 'Dos Hermanas', 'Delegación', 'HR MOTOR DOS HERMANAS', 'Sevilla'),
            $this->row('Coches.net', 'Alcalá de Guadaira', 'Delegación', 'HR MOTOR ALCALA DE GUADAIRA', 'Sevilla'),
            $this->row('Coches.net', 'Alicante', 'Delegación', 'HR MOTOR ALICANTE', 'Alicante'),
            $this->row('Coches.net', 'Alcoy', 'Delegación', 'HR MOTOR ALCOY', 'Alicante'),
            $this->row('Coches.net', 'Elche', 'Delegación', 'HR MOTOR ELCHE', 'Alicante'),
            $this->row('Coches.net', 'Bilbao', 'Delegación', 'HR MOTOR BILBAO', 'Bilbao'),
            $this->row('Coches.net', 'San Sebastián', 'Delegación', 'HR MOTOR SAN SEBASTIAN', 'San Sebastián'),
            $this->row('Coches.net', 'Girona', 'Delegación', 'HR MOTOR GIRONA', 'Girona'),
            $this->row('Coches.net', 'Lleida', 'Delegación', 'HR MOTOR LLEIDA', 'Lleida'),
            $this->row('Coches.net', 'Mallorca', 'Delegación', 'HR MOTOR MALLORCA', 'Mallorca'),
            $this->row('Coches.net', 'Murcia', 'Delegación', 'HR MOTOR MURCIA', 'Murcia'),
            $this->row('Coches.net', 'Valladolid', 'Delegación', 'HR MOTOR VALLADOLID', 'Valladolid'),
            $this->row('Coches.net', 'Gijón', 'Delegación', 'HR MOTOR GIJON', 'Asturias'),
            $this->row('Coches.net', 'A Coruña', 'Delegación', 'HR MOTOR A CORUÑA', 'A Coruña'),
            $this->row('Coches.net', 'Fontellas', 'Delegación', 'HR MOTOR FONTELLAS', 'Navarra'),
            $this->row('Coches.net', 'Pamplona', 'Delegación', 'HR MOTOR PAMPLONA', 'Navarra'),
            $this->row('Coches.net', 'Villarreal', 'Delegación', 'HR MOTOR VILLAREAL/ALMASSORA', 'Castellón'),
            $this->row('Coches.net', 'Villareal', 'Delegación', 'HR MOTOR VILLAREAL/ALMASSORA', 'Castellón'),
            $this->row('Coches.net', 'Castellón', 'Delegación', 'HR MOTOR CASTELLON', 'Castellón'),

            // 1000Anuncios separado, con normalización inicial similar a Coches.net.
            $this->row('1000Anuncios', 'Madrid', 'Grupo', null, 'Madrid'),
            $this->row('1000Anuncios', 'Barcelona', 'Grupo', null, 'Barcelona'),
            $this->row('1000Anuncios', 'Alcobendas', 'Delegación', 'HR MOTOR ALCOBENDAS', 'Madrid'),
            $this->row('1000Anuncios', 'Rivas', 'Delegación', 'HR MOTOR RIVAS-VACIA MADRID', 'Madrid'),
            $this->row('1000Anuncios', 'Torrejón', 'Delegación', 'HR MOTOR TORREJON', 'Madrid'),
            $this->row('1000Anuncios', 'Villalba', 'Delegación', 'HR MOTOR VILLALBA', 'Madrid'),
        ];
    }

    private function wallapopMappings(): array
    {
        return [
            $this->row('Wallapop', 'Madrid', 'Grupo', null, 'Madrid'),
            $this->row('Wallapop', 'Barcelona', 'Grupo', null, 'Barcelona'),
            $this->row('Wallapop', 'Valencia', 'Grupo', null, 'Valencia'),
            $this->row('Wallapop', 'Málaga', 'Grupo', null, 'Málaga'),
            $this->row('Wallapop', 'Coruña', 'Delegación', 'HR MOTOR A CORUÑA', 'A Coruña'),
            $this->row('Wallapop', 'A Coruña', 'Delegación', 'HR MOTOR A CORUÑA', 'A Coruña'),
            $this->row('Wallapop', 'Zaragoza', 'Delegación', 'HR MOTOR ZARAGOZA', 'Zaragoza'),
        ];
    }

    private function sumautoAutocasionMappings(): array
    {
        $base = [
            ['Alicante', 'Delegación', 'HR MOTOR ALICANTE', 'Alicante'],
            ['Asturias', 'Delegación', 'HR MOTOR GIJON', 'Asturias'],
            ['Barcelona', 'Grupo', null, 'Barcelona'],
            ['Madrid', 'Grupo', null, 'Madrid'],
            ['Navarra', 'Grupo', null, 'Navarra'],
            ['Sevilla', 'Grupo', 'HR MOTOR SEVILLA', 'Sevilla'],
            ['Valencia', 'Grupo', null, 'Valencia'],
            ['Bilbao', 'Delegación', 'HR MOTOR BILBAO', 'Bilbao'],
            ['Zaragoza', 'Delegación', 'HR MOTOR ZARAGOZA', 'Zaragoza'],
        ];

        $rows = [];

        foreach (['Sumauto', 'Autocasion'] as $portal) {
            foreach ($base as [$value, $type, $delegation, $group]) {
                $rows[] = $this->row($portal, $value, $type, $delegation, $group);
            }
        }

        return $rows;
    }

    private function cochesComMappings(): array
    {
        return [
            $this->row('Coches.com', 'Castellón', 'Delegación', 'HR MOTOR CASTELLON', 'Castellón'),
            $this->row('Coches.com', 'Alicante', 'Delegación', 'HR MOTOR ALICANTE', 'Alicante'),
            $this->row('Coches.com', 'Madrid', 'Grupo', null, 'Madrid'),
            $this->row('Coches.com', 'Barcelona', 'Grupo', null, 'Barcelona'),
            $this->row('Coches.com', 'Zaragoza', 'Delegación', 'HR MOTOR ZARAGOZA', 'Zaragoza'),
            $this->row('Coches.com', 'Sevilla', 'Delegación', 'HR MOTOR SEVILLA', 'Sevilla'),
            $this->row('Coches.com', 'Bilbao', 'Delegación', 'HR MOTOR BILBAO', 'Bilbao'),
            $this->row('Coches.com', 'Valencia', 'Grupo', null, 'Valencia'),
            $this->row('Coches.com', 'Collado Villalba', 'Delegación', 'HR MOTOR VILLALBA', 'Madrid'),
            $this->row('Coches.com', 'Rivas Vaciamadrid', 'Delegación', 'HR MOTOR RIVAS-VACIA MADRID', 'Madrid'),
            $this->row('Coches.com', 'Sant Boi', 'Delegación', 'HR MOTOR SANT BOI DE LLOBREGAT', 'Barcelona'),
            $this->row('Coches.com', 'Gijón', 'Delegación', 'HR MOTOR GIJON', 'Asturias'),
            $this->row('Coches.com', 'Torrejón de Ardoz', 'Delegación', 'HR MOTOR TORREJON', 'Madrid'),
            $this->row('Coches.com', 'Palma de Mallorca', 'Delegación', 'HR MOTOR MALLORCA', 'Mallorca'),
            $this->row('Coches.com', 'Alcobendas', 'Delegación', 'HR MOTOR ALCOBENDAS', 'Madrid'),
            $this->row('Coches.com', 'Lliçà de Vall', 'Delegación', 'HR MOTOR LLIÇÀ DE VALL', 'Barcelona'),
            $this->row('Coches.com', 'Valladolid', 'Delegación', 'HR MOTOR VALLADOLID', 'Valladolid'),
            $this->row('Coches.com', 'Badalona', 'Delegación', 'HR MOTOR BADALONA', 'Barcelona'),
            $this->row('Coches.com', 'Lleida', 'Delegación', 'HR MOTOR LLEIDA', 'Lleida'),
            $this->row('Coches.com', 'Girona', 'Delegación', 'HR MOTOR GIRONA', 'Girona'),
            $this->row('Coches.com', 'Tudela', 'Delegación', 'HR MOTOR FONTELLAS', 'Navarra'),
            $this->row('Coches.com', 'Alcalá de Guadaira', 'Delegación', 'HR MOTOR ALCALA DE GUADAIRA', 'Sevilla'),
            $this->row('Coches.com', 'San Sebastián', 'Delegación', 'HR MOTOR SAN SEBASTIAN', 'San Sebastián'),
            $this->row('Coches.com', 'Murcia', 'Delegación', 'HR MOTOR MURCIA', 'Murcia'),
            $this->row('Coches.com', 'Navarra', 'Grupo', null, 'Navarra'),
        ];
    }

    private function friendlyDelegationValue(string $delegationName): string
    {
        return str($delegationName)
            ->replace('HR MOTOR ', '')
            ->replace('RIVAS-VACIA MADRID', 'Rivas-Vaciamadrid')
            ->replace('SANT BOI DE LLOBREGAT', 'Sant Boi')
            ->replace('LLIÇÀ DE VALL', 'Lliçà de Vall')
            ->replace('VILLAREAL/ALMASSORA', 'Villarreal/Almassora')
            ->replace('ALCALA DE GUADAIRA', 'Alcalá de Guadaira')
            ->replace('SAN SEBASTIAN', 'San Sebastián')
            ->replace('A CORUÑA', 'A Coruña')
            ->replace('GIJON', 'Gijón')
            ->replace('TORREJON', 'Torrejón')
            ->replace('MALAGA CENTRO', 'Málaga Centro')
            ->replace('MALAGA', 'Málaga')
            ->replace('CASTELLON', 'Castellón')
            ->replace('SEDAVI', 'Sedaví')
            ->title()
            ->toString();
    }

    private function row(
        string $portalOriginal,
        string $receivedValue,
        ?string $type,
        ?string $delegationName,
        ?string $commercialGroup,
        string $status = 'active',
        ?string $validFrom = null,
        ?string $validTo = null
    ): array {
        return [
            'portal_original' => $portalOriginal,
            'received_value' => trim($receivedValue),
            'type' => $type,
            'delegation_name' => $delegationName,
            'commercial_group' => $commercialGroup,
            'status' => $status,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ];
    }
}