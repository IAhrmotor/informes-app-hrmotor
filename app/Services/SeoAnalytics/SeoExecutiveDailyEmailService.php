<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoExecutiveEmailDelivery;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class SeoExecutiveDailyEmailService
{
    public const DATASET = 'seo_executive_daily_email';

    public const SOURCE = 'application_mailer';

    public function __construct(
        private readonly SeoExecutiveEmailSettingsService $settings,
        private readonly SeoExecutiveMailReadinessService $readiness,
        private readonly SeoExecutiveDailyReportService $reports,
        private readonly SeoExecutiveMailSender $sender,
    ) {}

    /** @return array<string, mixed> */
    public function send(CarbonImmutable $reportDate): array
    {
        $recipients = $this->settings->recipients();
        if ($recipients === []) {
            throw new RuntimeException('No hay destinatarios configurados para el resumen ejecutivo SEO.');
        }
        if (! $this->readiness->ready()) {
            throw new RuntimeException('El transporte de correo no está preparado para el resumen ejecutivo SEO.');
        }

        $report = $this->reports->forDate($reportDate);
        $sent = 0;
        $alreadySent = 0;
        $failed = 0;
        $inProgress = 0;
        $confirmationPending = 0;

        foreach ($recipients as $recipient) {
            $delivery = $this->delivery($report->id, $reportDate, $recipient);

            if ($delivery->status === SeoExecutiveEmailDelivery::STATUS_SENT) {
                $alreadySent++;

                continue;
            }
            if ($delivery->status === SeoExecutiveEmailDelivery::STATUS_SENDING) {
                $inProgress++;

                continue;
            }
            if (! $this->claim($delivery->id)) {
                $delivery->refresh();
                if ($delivery->status === SeoExecutiveEmailDelivery::STATUS_SENT) {
                    $alreadySent++;
                } else {
                    $inProgress++;
                }

                continue;
            }

            try {
                $this->sender->send($recipient, $report->payload);
            } catch (Throwable $exception) {
                SeoExecutiveEmailDelivery::query()
                    ->whereKey($delivery->id)
                    ->where('status', SeoExecutiveEmailDelivery::STATUS_SENDING)
                    ->update([
                        'status' => SeoExecutiveEmailDelivery::STATUS_FAILED,
                        'error_message' => IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 2000),
                        'updated_at' => now(),
                    ]);
                $failed++;

                continue;
            }

            if ($this->confirmSent($delivery->id)) {
                $sent++;
            } else {
                $inProgress++;
                $confirmationPending++;
            }
        }

        $payload = $report->payload;
        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];

        return [
            'report_date' => $reportDate->toDateString(),
            'recipient_count' => count($recipients),
            'sent_count' => $sent,
            'already_sent_count' => $alreadySent,
            'failed_count' => $failed,
            'in_progress_count' => $inProgress,
            'confirmation_pending_count' => $confirmationPending,
            'metric_count' => count($payload['metrics'] ?? []),
            'ok_count' => (int) ($counts['ok'] ?? 0),
            'observation_count' => (int) ($counts['observation'] ?? 0),
            'deviation_count' => (int) ($counts['deviation'] ?? 0),
            'critical_count' => (int) ($counts['critical'] ?? 0),
            'not_evaluable_count' => (int) ($counts['not_evaluable'] ?? 0),
            'source_data_dates' => $payload['source_data_dates'] ?? [],
            'rule_versions' => $payload['rule_versions'] ?? [],
            'payload_hash' => $report->payload_hash,
        ];
    }

    private function claim(int $deliveryId): bool
    {
        return DB::table('seo_executive_email_deliveries')
            ->where('id', $deliveryId)
            ->where('status', SeoExecutiveEmailDelivery::STATUS_FAILED)
            ->update([
                'status' => SeoExecutiveEmailDelivery::STATUS_SENDING,
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_attempt_at' => now(),
                'error_message' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    private function confirmSent(int $deliveryId): bool
    {
        try {
            $affected = SeoExecutiveEmailDelivery::query()
                ->whereKey($deliveryId)
                ->where('status', SeoExecutiveEmailDelivery::STATUS_SENDING)
                ->update([
                    'status' => SeoExecutiveEmailDelivery::STATUS_SENT,
                    'sent_at' => now(),
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            if ($affected === 1) {
                return true;
            }
        } catch (Throwable) {
            // SMTP ya retornó correctamente: nunca convertir esta incertidumbre en un fallo reintentable.
        }

        return $this->preserveUnconfirmedDelivery($deliveryId);
    }

    private function preserveUnconfirmedDelivery(int $deliveryId): bool
    {
        try {
            $status = SeoExecutiveEmailDelivery::query()->whereKey($deliveryId)->value('status');
            if ($status === SeoExecutiveEmailDelivery::STATUS_SENT) {
                return true;
            }

            SeoExecutiveEmailDelivery::query()
                ->whereKey($deliveryId)
                ->where('status', '<>', SeoExecutiveEmailDelivery::STATUS_SENT)
                ->update([
                    'status' => SeoExecutiveEmailDelivery::STATUS_SENDING,
                    'sent_at' => null,
                    'error_message' => IntegrationErrorSanitizer::sanitizeMessage(
                        'SMTP aceptó el mensaje, pero no se pudo confirmar localmente la entrega.',
                        2000,
                    ),
                    'updated_at' => now(),
                ]);

            return SeoExecutiveEmailDelivery::query()->whereKey($deliveryId)->value('status')
                === SeoExecutiveEmailDelivery::STATUS_SENT;
        } catch (Throwable) {
            return false;
        }
    }

    private function delivery(int $reportId, CarbonImmutable $reportDate, string $recipient): SeoExecutiveEmailDelivery
    {
        $date = $reportDate->toDateString();
        $hash = hash('sha256', $recipient);
        $existing = SeoExecutiveEmailDelivery::query()
            ->whereDate('report_date', $date)
            ->where('recipient_hash', $hash)
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            return SeoExecutiveEmailDelivery::query()->create([
                'seo_executive_daily_report_id' => $reportId,
                'report_date' => $date,
                'recipient_email' => $recipient,
                'recipient_hash' => $hash,
                'status' => SeoExecutiveEmailDelivery::STATUS_FAILED,
                'attempt_count' => 0,
            ]);
        } catch (QueryException) {
            return SeoExecutiveEmailDelivery::query()
                ->whereDate('report_date', $date)
                ->where('recipient_hash', $hash)
                ->sole();
        }
    }
}
