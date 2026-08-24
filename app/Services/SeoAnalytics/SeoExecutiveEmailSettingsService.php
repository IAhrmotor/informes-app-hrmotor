<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoExecutiveEmailSetting;
use Illuminate\Support\Facades\Validator;

final class SeoExecutiveEmailSettingsService
{
    /** @return array<int, string> */
    public function recipients(): array
    {
        $recipients = SeoExecutiveEmailSetting::query()
            ->where('module_key', SeoExecutiveEmailSetting::MODULE)
            ->first()?->recipients;

        return is_array($recipients) ? $this->normalize($recipients) : [];
    }

    /** @return array<int, string> */
    public function validateTextarea(string $value): array
    {
        $value = trim($value);
        $lines = $value === '' ? [] : (preg_split('/\R/u', $value) ?: []);
        $lines = array_map(static fn (string $line): string => trim($line), $lines);

        Validator::make(
            ['email_recipients' => $lines],
            [
                'email_recipients' => ['required', 'array', 'min:1'],
                'email_recipients.*' => ['required', 'string', 'max:255', 'email:rfc'],
            ],
            [
                'email_recipients.required' => 'Configura al menos un destinatario.',
                'email_recipients.*.email' => 'Cada línea debe contener una dirección de correo válida.',
            ],
        )->validate();

        $recipients = $this->normalize($lines);
        Validator::make(
            ['email_recipients' => $recipients],
            ['email_recipients' => ['max:10']],
            ['email_recipients.max' => 'No se pueden configurar más de 10 destinatarios.'],
        )->validate();

        return $recipients;
    }

    /** @param array<int, string> $recipients */
    public function save(array $recipients, int $actorId): SeoExecutiveEmailSetting
    {
        return SeoExecutiveEmailSetting::query()->updateOrCreate(
            ['module_key' => SeoExecutiveEmailSetting::MODULE],
            [
                'recipients' => $this->normalize($recipients),
                'updated_by_report_user_id' => $actorId,
            ],
        );
    }

    /** @param array<int, string> $recipients
     * @return array<int, string>
     */
    private function normalize(array $recipients): array
    {
        $normalized = [];
        foreach ($recipients as $recipient) {
            $email = mb_strtolower(trim($recipient));
            if ($email !== '') {
                $normalized[$email] = $email;
            }
        }

        return array_values($normalized);
    }
}
