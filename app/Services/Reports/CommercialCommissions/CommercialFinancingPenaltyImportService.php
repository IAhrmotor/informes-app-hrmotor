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
    private const BASE_COLUMNS = [
        'month' => ['mescomision', 'mes'],
        'amount' => ['descontarcomercial4', 'descontaracomercial4'],
    ];

    private const OPTIONAL_IDENTITY_COLUMNS = [
        'commercial_id' => ['idcomercial', 'idsalesforce', 'idsalesforcecomercial', 'salesforceid'],
        'commercial_name' => ['nombrecomercial', 'nombre'],
    ];

    private const LEGACY_EMAIL_COLUMNS = ['emailcomercial', 'email'];

    public function __construct(
        private readonly XlsxWorkbookReader $reader,
    ) {}

    /** @return array{import: CommercialFinancingPenaltyImport, months: array<int, string>} */
    public function import(UploadedFile $file, ?int $uploadedByReportUserId): array
    {
        $rows = $this->extractRows($file->getRealPath() ?: $file->path());

        if ($rows === []) {
            throw new CommercialFinancingPenaltyImportException('No se encontraron filas de penalizacion con las columnas requeridas.');
        }

        $commercialIds = collect($rows)->pluck('commercial_salesforce_id')->filter()->unique()->values();
        $emails = collect($rows)->pluck('commercial_email')->filter()->unique()->values();
        $users = SalesforceUser::query()
            ->where(function ($query) use ($commercialIds, $emails): void {
                if ($commercialIds->isNotEmpty()) {
                    $query->whereIn('salesforce_id', $commercialIds);
                }

                if ($emails->isNotEmpty()) {
                    $method = $commercialIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('email', $emails);
                }
            })
            ->get(['salesforce_id', 'name', 'email']);
        $usersById = $users->keyBy(fn (SalesforceUser $user): string => (string) $user->salesforce_id);
        $usersByEmail = $users
            ->filter(fn (SalesforceUser $user): bool => filled($user->email))
            ->keyBy(fn (SalesforceUser $user): string => $this->emailKey($user->email));
        $months = collect($rows)->pluck('commission_month')->unique()->sort()->values()->all();
        $storedPath = $file->store('commission-penalties');

        $import = DB::transaction(function () use ($file, $storedPath, $uploadedByReportUserId, $rows, $usersById, $usersByEmail, $months): CommercialFinancingPenaltyImport {
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
                ->filter(fn (array $row): bool => $this->matchedUser($row, $usersById, $usersByEmail) === null)
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
                $salesforceUser = $this->matchedUser($row, $usersById, $usersByEmail);

                CommercialFinancingPenalty::query()->create([
                    'import_id' => $import->id,
                    'commission_month' => $row['commission_month'],
                    'commercial_email' => $row['commercial_email'] ?? $salesforceUser?->email,
                    'commercial_name' => $row['commercial_name'] ?? $salesforceUser?->name,
                    'salesforce_user_id' => $salesforceUser?->salesforce_id ?? $row['commercial_salesforce_id'],
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

    private function matchedUser(array $row, $usersById, $usersByEmail): ?SalesforceUser
    {
        $email = $this->emailKey($row['commercial_email'] ?? null);

        if ($email !== '') {
            return $usersByEmail->get($email);
        }

        return $usersById->get(trim((string) ($row['commercial_salesforce_id'] ?? '')));
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
                $commercialIdColumn = $header['columns']['commercial_id'];
                $commercialNameColumn = $header['columns']['commercial_name'];
                $commercialId = $commercialIdColumn !== null
                    ? trim((string) ($row[$commercialIdColumn] ?? ''))
                    : '';
                $commercialName = $commercialNameColumn !== null
                    ? trim((string) ($row[$commercialNameColumn] ?? ''))
                    : '';
                $email = $header['columns']['email'] !== null
                    ? trim((string) ($row[$header['columns']['email']] ?? ''))
                    : '';
                $monthValue = $row[$header['columns']['month']] ?? null;
                $amountValue = $row[$header['columns']['amount']] ?? null;

                if ($commercialId === '' && $commercialName === '' && $email === '' && blank($monthValue) && blank($amountValue)) {
                    continue;
                }

                $month = $this->parseMonth($monthValue);
                $amount = $this->parseAmount($amountValue);

                if ($header['mode'] === 'email' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

                $validIdentity = $header['mode'] === 'email'
                    ? filter_var($email, FILTER_VALIDATE_EMAIL)
                    : $commercialId !== '' && $commercialName !== '';

                if ($month === null || $amount === null || ! $validIdentity) {
                    continue;
                }

                $rows[] = [
                    'commission_month' => $month->toDateString(),
                    'commercial_salesforce_id' => $commercialId !== '' ? $commercialId : null,
                    'commercial_name' => $commercialName !== '' ? $commercialName : null,
                    'commercial_email' => $email !== '' ? Str::lower($email) : null,
                    // A positive input is also a discount; keep one negative sign in all cases.
                    'amount' => round(-abs($amount), 2),
                    'source_sheet' => $sheet['name'],
                    'source_row' => $sourceRow,
                    'raw_values' => [
                        'id_comercial' => $commercialId,
                        'nombre_comercial' => $commercialName,
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

            $baseColumns = [];

            foreach (self::BASE_COLUMNS as $field => $headers) {
                foreach ($headers as $header) {
                    if (array_key_exists($header, $normalized)) {
                        $baseColumns[$field] = $normalized[$header];
                        break;
                    }
                }
            }

            if (count($baseColumns) !== count(self::BASE_COLUMNS)) {
                continue;
            }

            $identityColumns = [];
            foreach (self::OPTIONAL_IDENTITY_COLUMNS as $field => $headers) {
                foreach ($headers as $header) {
                    if (array_key_exists($header, $normalized)) {
                        $identityColumns[$field] = $normalized[$header];
                        break;
                    }
                }
            }

            foreach (self::LEGACY_EMAIL_COLUMNS as $header) {
                if (array_key_exists($header, $normalized)) {
                    return [
                        'row_index' => $rowIndex,
                        'mode' => 'email',
                        'columns' => array_merge($baseColumns, [
                            'email' => $normalized[$header],
                            'commercial_id' => $identityColumns['commercial_id'] ?? null,
                            'commercial_name' => $identityColumns['commercial_name'] ?? null,
                        ]),
                    ];
                }
            }

            if (count($identityColumns) === count(self::OPTIONAL_IDENTITY_COLUMNS)) {
                return [
                    'row_index' => $rowIndex,
                    'mode' => 'identity',
                    'columns' => array_merge($baseColumns, $identityColumns, ['email' => null]),
                ];
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

        foreach (['!Y-m-d', '!Y-m', '!m/Y', '!m-Y', '!d/m/Y'] as $format) {
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
