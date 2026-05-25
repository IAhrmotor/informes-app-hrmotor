<?php

namespace App\Services\Reports\Calls;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class CallDescriptionParser
{
    public function parse(?string $description): array
    {
        $description = (string) $description;
        $destinationRaw = $this->field($description, 'Comercial destino');
        $answeredBy = $this->answeredBy($description);
        $duration = $this->field($description, 'Duracion de la llamada')
            ?? $this->field($description, 'Duración de la llamada');

        $result = $this->field($description, 'Resultado');
        if (blank($result) && $answeredBy !== null) {
            $result = 'ANSWERED';
        }

        return [
            'result_raw' => $this->clean($result),
            'type_raw' => $this->field($description, 'Tipo'),
            'client_phone' => $this->field($description, 'Telefono cliente') ?? $this->field($description, 'Teléfono cliente'),
            'fixed_phone' => $this->field($description, 'Telefono fijo llamado') ?? $this->field($description, 'Teléfono fijo llamado'),
            'destination_raw' => $destinationRaw,
            'destination_agent_code' => $this->agentCode($destinationRaw),
            'destination_agent_name' => $this->agentName($destinationRaw) ?? $answeredBy,
            'queue_raw' => $this->field($description, 'Cola'),
            'parsed_duration_seconds' => $this->parseDuration($duration),
            'uid_raw' => $this->field($description, 'UID llamada'),
            'puid_raw' => $this->field($description, 'PUID llamada'),
            'call_started_at' => $this->parseDateTime($this->field($description, 'Inicio')),
            'call_ended_at' => $this->parseDateTime($this->field($description, 'Fin')),
            'event_raw' => $this->field($description, 'Evento'),
        ];
    }

    private function field(string $description, string $label): ?string
    {
        $pattern = '/^\s*'.preg_quote($label, '/').'\s*:[ \t]*([^\r\n]*)/miu';

        if (preg_match($pattern, $description, $matches)) {
            return $this->clean($matches[1] ?? null);
        }

        return null;
    }

    private function answeredBy(string $description): ?string
    {
        if (! preg_match('/Respondido por\s+(.+?)(?:\s+atencion al cliente|\s+atención al cliente|$)/iu', $description, $matches)) {
            return null;
        }

        return $this->clean($matches[1] ?? null);
    }

    private function agentCode(?string $destination): ?string
    {
        if ($destination === null || ! preg_match('/\b(AG\d+)\b/i', $destination, $matches)) {
            return null;
        }

        return Str::upper($matches[1]);
    }

    private function agentName(?string $destination): ?string
    {
        if ($destination === null) {
            return null;
        }

        if (preg_match('/\bAG\d+\b\s*-\s*(.+)$/iu', $destination, $matches)) {
            return $this->clean($matches[1] ?? null);
        }

        return null;
    }

    private function parseDuration(?string $duration): ?int
    {
        if ($duration === null) {
            return null;
        }

        if (preg_match('/(\d+)\s*segundos?/iu', $duration, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', trim($duration), $matches)) {
            return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
        }

        return is_numeric($duration) ? (int) $duration : null;
    }

    private function parseDateTime(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
