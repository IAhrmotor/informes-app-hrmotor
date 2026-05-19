<?php

namespace App\Services\Reports\ReservasVentas;

use Illuminate\Support\Str;

class OpportunityPortalNormalizer
{
    public const UNCLASSIFIED = 'Sin clasificar';
    public const EXPOSITION = 'Exposición';
    public const WEB = 'Web';

    private const OFFICIAL_PORTALS = [
        'Atencion al cliente',
        'Autocasion',
        'Autopilot',
        'Captacion',
        'Coches.com',
        'Coches.net',
        self::EXPOSITION,
        'Facilitea',
        'Formulario',
        'Google Maps',
        'Marketing Cloud',
        'Meta',
        'Milanuncios',
        'Motor.es',
        'Sumauto',
        'Autoscout',
        'Wallapop',
        self::WEB,
        self::UNCLASSIFIED,
    ];

    public function normalize(?string $value): array
    {
        $raw = $this->clean($value);

        if ($raw === null || $raw === '-') {
            return $this->result($raw, self::UNCLASSIFIED, false, false);
        }

        $key = $this->key($raw);
        $portal = $this->map($key, $raw);

        if ($portal === null) {
            return $this->result($raw, self::UNCLASSIFIED, false, false);
        }

        return $this->result($raw, $portal, true, ! $this->isNonConclusiveKey($key));
    }

    public function isValidForLead(?string $value): bool
    {
        $normalized = $this->normalize($value);

        return $normalized['is_valid_final']
            && ! $this->isNonValidLeadPortal($normalized['raw'])
            && $normalized['portal'] !== self::EXPOSITION
            && $normalized['portal'] !== self::UNCLASSIFIED;
    }

    public function isUsefulSource(?string $value): bool
    {
        $normalized = $this->normalize($value);

        return $normalized['is_valid_final']
            && $normalized['portal'] !== self::UNCLASSIFIED
            && ! in_array($normalized['portal'], [self::EXPOSITION], true)
            && ! in_array($this->key((string) $normalized['raw']), ['3cx', 'llamadadirecta'], true);
    }

    public function officialPortals(): array
    {
        return self::OFFICIAL_PORTALS;
    }

    public function isOfficialFinal(?string $portal): bool
    {
        return in_array($portal, self::OFFICIAL_PORTALS, true);
    }

    public function isFallbackWebRaw(?string $value): bool
    {
        return in_array($this->key((string) $value), [
            'buscador',
            'chatbot',
            'chat',
            'direct',
            'directo',
            'direct',
        ], true);
    }

    public function isFallbackExpositionRaw(?string $value): bool
    {
        return $this->key((string) $value) === 'exposicion';
    }

    public function isUnclassifiedFallbackRaw(?string $value): bool
    {
        $key = $this->key((string) $value);

        return $key === '' || in_array($key, ['-', '3cx', 'llamadadirecta'], true);
    }

    public function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isNonValidLeadPortal(?string $value): bool
    {
        return $this->isNonConclusiveKey($this->key((string) $value));
    }

    private function isNonConclusiveKey(string $key): bool
    {
        return $key === ''
            || in_array($key, [
                '-',
                'exposicion',
                '3cx',
                'llamadadirecta',
                'direct',
                'directo',
                'buscador',
                'chatbot',
                'chat',
            ], true);
    }

    private function map(string $key, string $raw): ?string
    {
        if ($key === '-') {
            return self::UNCLASSIFIED;
        }

        if (str_contains($key, 'coches.net') || str_contains($key, 'cochesnet') || str_contains($key, 'coches net')) {
            return 'Coches.net';
        }

        if (str_contains($key, 'cochescom') || str_contains($key, 'coches com') || $key === 'coches.com') {
            return 'Coches.com';
        }

        if (in_array($key, ['autocasion', 'autoocasion', 'auto ocasion'], true)) {
            return 'Autocasion';
        }

        if (in_array($key, ['sumauto', 'sum auto', 'su moto'], true)) {
            return 'Sumauto';
        }

        if (in_array($key, ['milanuncios', 'mil anuncios', '1000 anuncios', 'milanuncios.com'], true)) {
            return 'Milanuncios';
        }

        if ($key === 'wallapop') {
            return 'Wallapop';
        }

        if (in_array($key, ['meta', 'facebook', 'facebook ads', 'facebook.com', 'instagram', 'instagram ads', 'ig', 'meta ads'], true)) {
            return 'Meta';
        }

        if (in_array($key, ['google maps', 'maps', 'ficha google', 'google my business'], true)) {
            return 'Google Maps';
        }

        if (in_array($key, [
            'web',
            'google',
            'google generico',
            'buscador',
            'direct',
            'directo',
            'chatbot',
            'chat',
            'formulario web',
            'pagina web',
            'whatsapp',
            'youtube',
            'youtube.com',
            'bing.com',
            'es.search.yahoo.com',
            'yahoo',
        ], true)) {
            return self::WEB;
        }

        if (str_starts_with($key, 'google ') && $key !== 'google maps') {
            return self::WEB;
        }

        if ($key === 'facilitea') {
            return 'Facilitea';
        }

        if ($key === 'marketing cloud') {
            return 'Marketing Cloud';
        }

        if (in_array($key, ['motor.es', 'motor'], true)) {
            return 'Motor.es';
        }

        if (in_array($key, ['autoscout', 'auto scout', 'scout'], true)) {
            return 'Autoscout';
        }

        if (in_array($key, ['captacion', 'captacion comercial'], true)) {
            return 'Captacion';
        }

        if (in_array($key, ['atencion al cliente', 'atencion cliente', 'cliente'], true)) {
            return 'Atencion al cliente';
        }

        if ($key === 'autopilot') {
            return 'Autopilot';
        }

        if ($key === 'formulario') {
            return 'Formulario';
        }

        if ($key === 'exposicion') {
            return self::EXPOSITION;
        }

        return null;
    }

    private function result(?string $raw, string $portal, bool $valid, bool $conclusive): array
    {
        return [
            'raw' => $raw,
            'portal' => $portal,
            'is_valid_final' => $valid,
            'is_conclusive' => $conclusive,
        ];
    }

    private function key(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replace(['_', '/', '\\'], [' ', ' ', ' '])
            ->replaceMatches('/[()]+/', '')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
