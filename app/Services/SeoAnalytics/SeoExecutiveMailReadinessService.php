<?php

namespace App\Services\SeoAnalytics;

final class SeoExecutiveMailReadinessService
{
    /** @return array{ready: bool, label: string} */
    public function status(): array
    {
        $mailer = trim((string) config('mail.default'));
        $from = mb_strtolower(trim((string) config('mail.from.address')));
        $ready = $mailer !== ''
            && $this->mailerIsOperational($mailer)
            && $from !== 'hello@example.com'
            && filter_var($from, FILTER_VALIDATE_EMAIL) !== false;

        return [
            'ready' => $ready,
            'label' => $ready
                ? 'Transporte de correo operativo'
                : 'Transporte de correo pendiente de configurar',
        ];
    }

    public function ready(): bool
    {
        return $this->status()['ready'];
    }

    /** @param array<int, string> $visited */
    private function mailerIsOperational(string $mailer, array $visited = []): bool
    {
        if (in_array($mailer, $visited, true)) {
            return false;
        }

        $configuration = config('mail.mailers.'.$mailer);
        if (! is_array($configuration)) {
            return false;
        }

        $transport = (string) ($configuration['transport'] ?? $mailer);
        if (in_array($transport, ['log', 'array'], true)) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $mailers = $configuration['mailers'] ?? [];

            return is_array($mailers)
                && $mailers !== []
                && collect($mailers)->every(
                    fn (mixed $child): bool => is_string($child)
                        && $this->mailerIsOperational($child, [...$visited, $mailer])
                );
        }

        return $transport !== '';
    }
}
