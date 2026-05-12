<?php

namespace Database\Seeders;

use App\Models\MasterFormSenderMapping;
use Illuminate\Database\Seeder;

class MasterFormSenderMappingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = array_merge(
            $this->wallapop(),
            $this->cochesNet(),
            $this->milAnuncios(),
            $this->sumautoAutocasion(),
            $this->cochesCom(),
            $this->milAnunciosPlaceholders()
        );

        foreach ($rows as $row) {
            MasterFormSenderMapping::updateOrCreate(
                [
                    'portal_original' => $row['portal_original'],
                    'portal_value' => $row['portal_value'],
                    'sender_email' => $row['sender_email'],
                ],
                [
                    'receiver_account' => $row['receiver_account'] ?? null,
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

    private function wallapop(): array
    {
        return [
            $this->row('Wallapop', 'Madrid', 'leadsmadrid@hrmotor.com', 'Grupo', null, 'Madrid'),
            $this->row('Wallapop', 'Barcelona', 'leadsbarcelona@hrmotor.com', 'Grupo', null, 'Barcelona'),
            $this->row('Wallapop', 'Valencia', 'leadsvalencia@hrmotor.com', 'Grupo', null, 'Valencia'),
            $this->row('Wallapop', 'Zaragoza', 'leadszaragoza@hrmotor.com', 'Delegación', 'HR MOTOR ZARAGOZA', 'Zaragoza'),
            $this->row('Wallapop', 'Málaga', 'leads.gasset@hrmotor.com', 'Grupo', null, 'Málaga'),
            $this->row('Wallapop', 'Coruña', 'leadsacoruna@hrmotor.com', 'Delegación', 'HR MOTOR A CORUÑA', 'A Coruña'),
        ];
    }

    private function cochesNet(): array
    {
        return [
            $this->row('Coches.net', 'Villalba', 'leadsvillalba@hrmotor.com', 'Delegación', 'HR MOTOR VILLALBA', 'Madrid'),
            $this->row('Coches.net', 'Rivas', 'leadsrivas@hrmotor.com', 'Delegación', 'HR MOTOR RIVAS-VACIA MADRID', 'Madrid'),
            $this->row('Coches.net', 'Alcobendas', 'leadsalcobendas@hrmotor.com', 'Delegación', 'HR MOTOR ALCOBENDAS', 'Madrid'),
            $this->row('Coches.net', 'Torrejón', 'leadstorrejon@hrmotor.com', 'Delegación', 'HR MOTOR TORREJON', 'Madrid'),
            $this->row('Coches.net', 'Lleida', 'leadslleida@hrmotor.com', 'Delegación', 'HR MOTOR LLEIDA', 'Lleida'),
            $this->row('Coches.net', 'Girona', 'leadsgirona@hrmotor.com', 'Delegación', 'HR MOTOR GIRONA', 'Girona'),
            $this->row('Coches.net', 'Alcoy', 'leadsalcoy@hrmotor.com', 'Delegación', 'HR MOTOR ALCOY', 'Alicante'),
            $this->row('Coches.net', 'Elche', 'leadselche@hrmotor.com', 'Delegación', 'HR MOTOR ELCHE', 'Alicante'),
            $this->row('Coches.net', 'Villarreal', 'leadsvillareal@hrmotor.com', 'Delegación', 'HR MOTOR VILLAREAL/ALMASSORA', 'Castellón'),
            $this->row('Coches.net', 'Castellón', 'leadscastellon@hrmotor.com', 'Delegación', 'HR MOTOR CASTELLON', 'Castellón'),
            $this->row('Coches.net', 'Fontellas', 'leadsfontellas@hrmotor.com', 'Delegación', 'HR MOTOR FONTELLAS', 'Navarra'),
            $this->row('Coches.net', 'Pamplona', 'leadspamplona@hrmotor.com', 'Delegación', 'HR MOTOR PAMPLONA', 'Navarra'),
            $this->row('Coches.net', 'Dos Hermanas', 'leadsdoshermanas@hrmotor.com', 'Delegación', 'HR MOTOR DOS HERMANAS', 'Sevilla'),
            $this->row('Coches.net', 'Alcalá de Guadaira', 'leadsalcala@hrmotor.com', 'Delegación', 'HR MOTOR ALCALA DE GUADAIRA', 'Sevilla'),
            $this->row('Coches.net', 'Sevilla', 'leadssevilla@hrmotor.com', 'Delegación', 'HR MOTOR SEVILLA', 'Sevilla'),
            $this->row('Coches.net', 'Málaga Centro', 'leadsalmachar@hrmotor.com', 'Delegación', 'HR MOTOR MALAGA CENTRO', 'Málaga'),
            $this->row('Coches.net', 'Málaga', 'leads.gasset@hrmotor.com', 'Delegación', 'HR MOTOR MALAGA', 'Málaga'),
            $this->row('Coches.net', 'Murcia', 'leadsmurcia@hrmotor.com', 'Delegación', 'HR MOTOR MURCIA', 'Murcia'),
            $this->row('Coches.net', 'Valladolid', 'leadsvalladolid@hrmotor.com', 'Delegación', 'HR MOTOR VALLADOLID', 'Valladolid'),
            $this->row('Coches.net', 'A Coruña', 'leadsacoruna@hrmotor.com', 'Delegación', 'HR MOTOR A CORUÑA', 'A Coruña'),
            $this->row('Coches.net', 'Gijón', 'leadsgijon@hrmotor.com', 'Delegación', 'HR MOTOR GIJON', 'Asturias'),
            $this->row('Coches.net', 'Zaragoza', 'leadszaragoza@hrmotor.com', 'Delegación', 'HR MOTOR ZARAGOZA', 'Zaragoza'),
            $this->row('Coches.net', 'Bilbao', 'leadsbilbao@hrmotor.com', 'Delegación', 'HR MOTOR BILBAO', 'Bilbao'),
            $this->row('Coches.net', 'San Sebastián', 'leadsoiartzun@hrmotor.com', 'Delegación', 'HR MOTOR SAN SEBASTIAN', 'San Sebastián'),
            $this->row('Coches.net', 'Paterna', 'leadspaterna@hrmotor.com', 'Delegación', 'HR MOTOR PATERNA', 'Valencia'),
            $this->row('Coches.net', 'Sedaví', 'leadssedavi@hrmotor.com', 'Delegación', 'HR MOTOR SEDAVI', 'Valencia'),
            $this->row('Coches.net', 'Valencia', 'leadsvalencia@hrmotor.com', 'Delegación', 'HR MOTOR VALENCIA', 'Valencia'),
            $this->row('Coches.net', 'Mallorca', 'leads.mallorca@hrmotor.com', 'Delegación', 'HR MOTOR MALLORCA', 'Mallorca'),
            $this->row('Coches.net', 'Badalona', 'leadsbadalona@hrmotor.com', 'Delegación', 'HR MOTOR BADALONA', 'Barcelona'),
            $this->row('Coches.net', 'Lliçà', 'leadsllica@hrmotor.com', 'Delegación', 'HR MOTOR LLIÇÀ DE VALL', 'Barcelona'),
            $this->row('Coches.net', 'Sant Boi', 'leadssantboi@hrmotor.com', 'Delegación', 'HR MOTOR SANT BOI DE LLOBREGAT', 'Barcelona'),
            $this->row('Coches.net', 'Manresa', 'leadsmanresa@hrmotor.com', 'Delegación', 'HR MOTOR MANRESA', 'Barcelona'),
            $this->row('Coches.net', 'Alicante', 'leadsalicante@hrmotor.com', 'Delegación', 'HR MOTOR ALICANTE', 'Alicante'),

            // 1000Anuncios comparte normalización inicial con Coches.net, pero se mantiene como portal separado.
            $this->row('1000Anuncios', 'Villalba', 'leadsvillalba@hrmotor.com', 'Delegación', 'HR MOTOR VILLALBA', 'Madrid'),
            $this->row('1000Anuncios', 'Rivas', 'leadsrivas@hrmotor.com', 'Delegación', 'HR MOTOR RIVAS-VACIA MADRID', 'Madrid'),
            $this->row('1000Anuncios', 'Alcobendas', 'leadsalcobendas@hrmotor.com', 'Delegación', 'HR MOTOR ALCOBENDAS', 'Madrid'),
            $this->row('1000Anuncios', 'Torrejón', 'leadstorrejon@hrmotor.com', 'Delegación', 'HR MOTOR TORREJON', 'Madrid'),
            $this->row('1000Anuncios', 'Alicante', 'leadsalicante@hrmotor.com', 'Delegación', 'HR MOTOR ALICANTE', 'Alicante'),
            $this->row('1000Anuncios', 'Zaragoza', 'leadszaragoza@hrmotor.com', 'Delegación', 'HR MOTOR ZARAGOZA', 'Zaragoza'),
        ];
    }

    private function milAnuncios(): array
    {
        return [
            $this->row('Milanuncios', null, 'leadsalicante@hrmotor.com', 'Delegación', 'HR MOTOR ALICANTE', 'Alicante'),
            $this->row('Milanuncios', null, 'leadsgijon@hrmotor.com', 'Delegación', 'HR MOTOR GIJON', 'Asturias'),
            $this->row('Milanuncios', null, 'leadsbarcelona@hrmotor.com', 'Grupo', null, 'Barcelona'),
            $this->row('Milanuncios', null, 'leadsmadrid@hrmotor.com', 'Grupo', null, 'Madrid'),
            $this->row('Milanuncios', null, 'leadssevilla@hrmotor.com', 'Grupo', null, 'Sevilla'),
            $this->row('Milanuncios', null, 'leadsvalencia@hrmotor.com', 'Grupo', null, 'Valencia'),
            $this->row('Milanuncios', null, 'leadszaragoza@hrmotor.com', 'Delegación', 'HR MOTOR ZARAGOZA', 'Zaragoza'),
        ];
    }

    private function sumautoAutocasion(): array
    {
        $rows = [
            ['Alicante', 'leadsalicante@hrmotor.com', 'Delegación', 'HR MOTOR ALICANTE', 'Alicante'],
            ['Asturias', 'leadsgijon@hrmotor.com', 'Delegación', 'HR MOTOR GIJON', 'Asturias'],
            ['Barcelona', 'leadsbarcelona@hrmotor.com', 'Grupo', null, 'Barcelona'],
            ['Madrid', 'leadsmadrid@hrmotor.com', 'Grupo', null, 'Madrid'],
            ['Navarra', 'leadspamplona@hrmotor.com', 'Grupo', null, 'Navarra'],
            ['Sevilla', 'leadssevilla@hrmotor.com', 'Grupo', 'HR MOTOR SEVILLA', 'Sevilla'],
            ['Valencia', 'leadsvalencia@hrmotor.com', 'Grupo', null, 'Valencia'],
            ['Bilbao', 'leadsbilbao@hrmotor.com', 'Delegación', 'HR MOTOR BILBAO', 'Bilbao'],
            ['Zaragoza', 'leadszaragoza@hrmotor.com', 'Delegación', 'HR MOTOR ZARAGOZA', 'Zaragoza'],
        ];

        $result = [];

        foreach (['Sumauto', 'Autocasion'] as $portal) {
            foreach ($rows as [$value, $email, $type, $delegation, $group]) {
                $result[] = $this->row($portal, $value, $email, $type, $delegation, $group);
            }
        }

        return $result;
    }

    private function cochesCom(): array
    {
        return [
            $this->row('Coches.com', 'Castellón', 'leadscastellon@hrmotor.com', 'Delegación', 'HR MOTOR CASTELLON', 'Castellón'),
            $this->row('Coches.com', 'Alicante', 'leadsalicante@hrmotor.com', 'Delegación', 'HR MOTOR ALICANTE', 'Alicante'),
            $this->row('Coches.com', 'Madrid', 'leadsmadrid@hrmotor.com', 'Grupo', null, 'Madrid'),
            $this->row('Coches.com', 'Barcelona', 'leadsbarcelona@hrmotor.com', 'Grupo', null, 'Barcelona'),
            $this->row('Coches.com', 'Zaragoza', 'leadszaragoza@hrmotor.com', 'Delegación', 'HR MOTOR ZARAGOZA', 'Zaragoza'),
            $this->row('Coches.com', 'Sevilla', 'leadssevilla@hrmotor.com', 'Delegación', 'HR MOTOR SEVILLA', 'Sevilla'),
            $this->row('Coches.com', 'Bilbao', 'leadsbilbao@hrmotor.com', 'Delegación', 'HR MOTOR BILBAO', 'Bilbao'),
            $this->row('Coches.com', 'Valencia', 'leadsvalencia@hrmotor.com', 'Grupo', null, 'Valencia'),
            $this->row('Coches.com', 'Collado Villalba', 'leadsmadrid@hrmotor.com', 'Delegación', 'HR MOTOR VILLALBA', 'Madrid'),
            $this->row('Coches.com', 'Rivas Vaciamadrid', 'leadsrivas@hrmotor.com', 'Delegación', 'HR MOTOR RIVAS-VACIA MADRID', 'Madrid'),
            $this->row('Coches.com', 'Sant Boi', 'leadssantboi@hrmotor.com', 'Delegación', 'HR MOTOR SANT BOI DE LLOBREGAT', 'Barcelona'),
            $this->row('Coches.com', 'Gijón', 'leadsgijon@hrmotor.com', 'Delegación', 'HR MOTOR GIJON', 'Asturias'),
            $this->row('Coches.com', 'Torrejón de Ardoz', 'leadsmadrid@hrmotor.com', 'Delegación', 'HR MOTOR TORREJON', 'Madrid'),
            $this->row('Coches.com', 'Palma de Mallorca', 'leads.mallorca@hrmotor.com', 'Delegación', 'HR MOTOR MALLORCA', 'Mallorca'),
            $this->row('Coches.com', 'Alcobendas', 'leadsalcobendas@hrmotor.com', 'Delegación', 'HR MOTOR ALCOBENDAS', 'Madrid'),
            $this->row('Coches.com', 'Lliçà de Vall', 'leadsllica@hrmotor.com', 'Delegación', 'HR MOTOR LLIÇÀ DE VALL', 'Barcelona'),
            $this->row('Coches.com', 'Valladolid', 'leadsvalladolid@hrmotor.com', 'Delegación', 'HR MOTOR VALLADOLID', 'Valladolid'),
            $this->row('Coches.com', 'Badalona', 'leadsbadalona@hrmotor.com', 'Delegación', 'HR MOTOR BADALONA', 'Barcelona'),
            $this->row('Coches.com', 'Lleida', 'leadslleida@hrmotor.com', 'Delegación', 'HR MOTOR LLEIDA', 'Lleida'),
            $this->row('Coches.com', 'Girona', 'leadsgirona@hrmotor.com', 'Delegación', 'HR MOTOR GIRONA', 'Girona'),
            $this->row('Coches.com', 'Tudela', 'leadstudela@hrmotor.com', 'Delegación', 'HR MOTOR FONTELLAS', 'Navarra'),
            $this->row('Coches.com', 'Alcalá de Guadaira', 'leadsalcala@hrmotor.com', 'Delegación', 'HR MOTOR ALCALA DE GUADAIRA', 'Sevilla'),
            $this->row('Coches.com', 'San Sebastián', 'leadsguipuzcoa@hrmotor.com', 'Delegación', 'HR MOTOR SAN SEBASTIAN', 'San Sebastián'),
            $this->row('Coches.com', 'Murcia', 'leadsmurcia@hrmotor.com', 'Delegación', 'HR MOTOR MURCIA', 'Murcia'),
            $this->row('Coches.com', 'Navarra', 'leadspamplona@hrmotor.com', 'Grupo', null, 'Navarra'),
        ];
    }

    private function milAnunciosPlaceholders(): array
    {
        return [
            // Placeholder explícito para recordar que Milanuncios puede requerir ampliación.
            // Se mantiene separado como portal/grupo portal.
        ];
    }

    private function row(
        string $portalOriginal,
        ?string $portalValue,
        string $senderEmail,
        ?string $type,
        ?string $delegationName,
        ?string $commercialGroup,
        ?string $receiverAccount = null,
        string $status = 'active',
        ?string $validFrom = null,
        ?string $validTo = null
    ): array {
        return [
            'portal_original' => $portalOriginal,
            'portal_value' => $portalValue,
            'sender_email' => mb_strtolower(trim($senderEmail)),
            'receiver_account' => $receiverAccount,
            'type' => $type,
            'delegation_name' => $delegationName,
            'commercial_group' => $commercialGroup,
            'status' => $status,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ];
    }
}