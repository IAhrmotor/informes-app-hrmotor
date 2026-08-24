<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoExecutiveDailyReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

final class SeoExecutiveDailyReportService
{
    public function __construct(private readonly SeoExecutiveDailyReportDatasetService $dataset) {}

    public function forDate(CarbonImmutable $reportDate): SeoExecutiveDailyReport
    {
        $date = $reportDate->toDateString();
        $existing = SeoExecutiveDailyReport::query()->whereDate('report_date', $date)->first();
        if ($existing) {
            return $existing;
        }

        $payload = $this->dataset->build($reportDate);

        try {
            return SeoExecutiveDailyReport::query()->create([
                'report_date' => $date,
                'generated_at' => now(),
                'payload' => $payload,
                'payload_hash' => $this->payloadHash($payload),
            ]);
        } catch (QueryException) {
            return SeoExecutiveDailyReport::query()->whereDate('report_date', $date)->sole();
        }
    }

    /** @param array<string, mixed> $payload */
    public function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
