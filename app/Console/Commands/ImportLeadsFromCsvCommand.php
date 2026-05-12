<?php

namespace App\Console\Commands;

use App\Models\LeadNormalized;
use App\Models\LeadRaw;
use App\Services\Salesforce\SalesforceLeadCsvMapper;
use Illuminate\Console\Command;
use SplFileObject;

class ImportLeadsFromCsvCommand extends Command
{
    protected $signature = 'leads:import-csv
        {file : Ruta relativa o absoluta del CSV}
        {--fresh : Borra leads_raw y leads_normalized antes de importar}
        {--normalize : Ejecuta leads:normalize al terminar la importación}
        {--delimiter=, : Delimitador del CSV}
        {--limit= : Limita el número de filas importadas}';

    protected $description = 'Importa leads desde CSV exportado de Salesforce a leads_raw';

    public function handle(SalesforceLeadCsvMapper $mapper): int
    {
        $inputPath = (string) $this->argument('file');
        $absolutePath = $this->resolvePath($inputPath);

        if ($absolutePath === null) {
            $this->error("No existe el archivo: {$inputPath}");
            $this->line('Rutas probadas:');
            $this->line(base_path($inputPath));
            $this->line(storage_path('app/' . $inputPath));
            $this->line(storage_path('app/private/' . $inputPath));

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            LeadNormalized::query()->delete();
            LeadRaw::query()->delete();

            $this->info('Tablas leads_normalized y leads_raw vaciadas.');
        }

        $delimiter = (string) $this->option('delimiter');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $file = new SplFileObject($absolutePath);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl($delimiter);

        $headers = null;
        $processed = 0;
        $imported = 0;
        $skipped = 0;

        foreach ($file as $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            if ($headers === null) {
                $headers = $this->normalizeHeaders($row);
                continue;
            }

            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $processed++;

            $row = $this->combineRow($headers, $row);
            $mapped = $mapper->map($row);

            if (blank($mapped['salesforce_id'])) {
                $skipped++;
                continue;
            }

            LeadRaw::updateOrCreate(
                ['salesforce_id' => $mapped['salesforce_id']],
                $mapped
            );

            $imported++;
        }

        $this->info('Importación CSV completada.');
        $this->line("Archivo: {$absolutePath}");
        $this->line("Procesados: {$processed}");
        $this->line("Importados/actualizados: {$imported}");
        $this->line("Omitidos: {$skipped}");

        if ($this->option('normalize')) {
            $this->newLine();
            $this->call('leads:normalize', [
                '--fresh' => true,
            ]);
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $inputPath): ?string
    {
        $candidates = [
            $inputPath,
            base_path($inputPath),
            storage_path('app/' . $inputPath),
            storage_path('app/private/' . $inputPath),
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $header = trim((string) $header);

            return preg_replace('/^\xEF\xBB\xBF/', '', $header);
        }, $headers);
    }

    private function combineRow(array $headers, array $row): array
    {
        $row = array_pad($row, count($headers), null);
        $row = array_slice($row, 0, count($headers));

        return array_combine($headers, $row);
    }
}