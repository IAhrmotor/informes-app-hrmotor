<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialFinancingPenalty;
use App\Models\CommercialFinancingPenaltyImport;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\Import\CommercialFinancingPenaltyImportException;
use App\Services\Reports\CommercialCommissions\Import\XlsxWorkbookReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommercialFinancingPenaltyImportService
{
    private const REQUIRED_COLUMNS = [
        'email' => ['emailcomercial', 'email'],
        'month' => ['mescomision', 'mes'],
        'amount' => ['descontarcomercial4', 'descontaracomercial4'],
    ];

    public function __construct(
        private readonly XlsxWorkbookReader $reader,
    ) {
    }

    /** @return array{import: CommercialFinancingPenaltyImport, months: array<int, string>} */
    public function import(UploadedFile $file, ?int $uploadedByReportUserId): array
    {
        $rows = $this->extractRows($file->getRealPath() ?: $file->path());

        if ($rows === []) {
            throw new CommercialFinancingPenaltyImportException('No se encontraron filas de penalizacion con las columnas requeridas.');
        }

        $emails = collect($rows)->pluck('commercial_email')->unique()->values();
        $usersByEmail = SalesforceUser::query()
            ->whereIn('email', $emails)
            ->get(['salesforce_id', 'email'])
            ->keyBy(fn (SalesforceUser $user): string => $this->emailKey($user->email));
        $months = collect($rows)->pluck('commission_month')->unique()->sort()->values()->all();
        $storedPath = $file->store('commission-penalties');

        $import = DB::transaction(function () use ($file, $storedPath, $uploadedByReportUserId, $rows, $usersByEmail, $months): CommercialFinancingPenaltyImport {
            // A newer file is the source of truth for each period it contains.
            CommercialFinancingPenalty::query()
                ->where('is_active', true)
                ->where(function ($query) use ($months): void {
                    foreach ($months as $month) {
                        $query->orWhereDate('commission_month', $month);
                    }
                })
                ->update([
                    'is_active' => false,
                    'deactivated_at' => now(),
                    'updated_at' => now(),
                ]);

            $unmatchedRows = collect($rows)
                ->filter(fn (array $row): bool => ! $usersByEmail->has($this->emailKey($row['commercial_email'])))
                ->count();
            $import = CommercialFinancingPenaltyImport::query()->create([
                'uploaded_by_report_user_id' => $uploadedByReportUserId,
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'rows_read' => count($rows),
                'rows_imported' => count($rows),
                'rows_unmatched' => $unmatchedRows,
                'commission_months' => $months,
            ]);

            foreach ($rows as $row) {
                $salesforceUser = $usersByEmail->get($this->emailKey($row['commercial_email']));

                CommercialFinancingPenalty::query()->create([
                    'import_id' => $import->id,
                    'commission_month' => $row['commission_month'],
                    'commercial_email' => $row['commercial_email'],
                    'salesforce_user_id' => $salesforceUser?->salesforce_id,
                    'amount' => $row['amount'],
                    'source_sheet' => $row['source_sheet'],
                    'source_row' => $row['source_row'],
                    'raw_values' => $row['raw_values'],
                    'is_active' => true,
                ]);
            }

            return $import;
        });

        return [
            'import' => $import,
            'months' => $months,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function extractRows(string $path): array
    {
        $rows = [];
        $errors = [];
        $foundHeader = false;

        foreach ($this->reader->sheets($path) as $sheet) {
            $header = $this->findHeader($sheet['rows']);

            if ($header === null) {
                continue;
            }

            $foundHeader = true;

            foreach (array_slice($sheet['rows'], $header['row_index'] + 1) as $relativeIndex => $row) {
                $sourceRow = (int) ($row['__row_number'] ?? ($header['row_index'] + $relativeIndex + 2));
                $email = trim((string) ($row[$header['columns']['email']] ?? ''));
                $monthValue = $row[$header['columns']['month']] ?? null;
                $amountValue = $row[$header['columns']['amount']] ?? null;

                if ($email === '' && blank($monthValue) && blank($amountValue)) {
                    continue;
                }

                $month = $this->parseMonth($monthValue);
                $amount = $this->parseAmount($amountValue);

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "{$sheet['name']} fila {$sourceRow}: Email comercial no valido.";
                }

                if ($month === null) {
                    $errors[] = "{$sheet['name']} fila {$sourceRow}: Mes comision no valido.";
                }

                if ($amount === null) {
                    $errors[] = "{$sheet['name']} fila {$sourceRow}: descontar comercial 4% no es numerico.";
                }

                if (count($errors) >= 10) {
                    break 2;
                }

                if ($month === null || $amount === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $rows[] = [
                    'commission_month' => $month->toDateString(),
                    'commercial_email' => Str::lower($email),
                    // A positive input is also a discount; keep one negative sign in all cases.
                    'amount' => round(-abs($amount), 2),
                    'source_sheet' => $sheet['name'],
                    'source_row' => $sourceRow,
                    'raw_values' => [
                        'email_comercial' => $email,
                        'mes_comision' => $monthValue,
                        'descontar_comercial_4' => $amountValue,
                    ],
                ];
            }
        }

        if (! $foundHeader) {
            throw new CommercialFinancingPenaltyImportException('Faltan las columnas requeridas: Mes comision, Email comercial y descontar comercial 4%.');
        }

        if ($errors !== []) {
            throw new CommercialFinancingPenaltyImportException(implode(' ', $errors));
        }

        return $rows;
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function findHeader(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 20, preserve_keys: true) as $rowIndex => $row) {
            $normalized = [];

            foreach ($row as $column => $value) {
                $normalized[$this->headerKey($value)] = $column;
            }

            $columns = [];

            foreach (self::REQUIRED_COLUMNS as $field => $headers) {
                foreach ($headers as $header) {
                    if (array_key_exists($header, $normalized)) {
                        $columns[$field] = $normalized[$header];
                        break;
                    }
                }
            }

            if (count($columns) === count(self::REQUIRED_COLUMNS)) {
                return ['row_index' => $rowIndex, 'columns' => $columns];
            }
        }

        return null;
    }

    private function headerKey(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::ascii(Str::lower(trim((string) $value)))) ?: '';
    }

    private function emailKey(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }

    private function parseAmount(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^0-9,.-]/', '', str_replace(' ', '', (string) $value));

        if ($normalized === null || $normalized === '' || $normalized === '-') {
            return null;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $normalized = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $normalized))
                : str_replace(',', '', $normalized);
        } elseif ($lastComma !== false) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function parseMonth(mixed $value): ?CarbonImmutable
    {
        if (is_numeric($value) && (float) $value > 20_000 && (float) $value < 80_000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $value))->startOfMonth();
        }

        $raw = trim((string) $value);

        foreach (['Y-m-d', 'Y-m', 'm/Y', 'm-Y', 'd/m/Y'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $raw)->startOfMonth();
            } catch (\Throwable) {
            }
        }

        $normalized = Str::ascii(Str::lower($raw));
        $months = [
            'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
            'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
            'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        ];

        foreach ($months as $name => $number) {
            if (preg_match('/'.$name.'\s*(\d{2,4})/', $normalized, $match)) {
                $year = (int) $match[1];
                $year += $year < 100 ? 2000 : 0;

                return CarbonImmutable::create($year, $number, 1);
            }
        }

        return null;
    }
}
