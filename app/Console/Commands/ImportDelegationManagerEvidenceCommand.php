<?php

namespace App\Console\Commands;

use App\Services\Reports\CommercialCommissions\DelegationManagerEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImportDelegationManagerEvidenceCommand extends Command
{
    protected $signature = 'commissions:import-delegation-manager-evidence
        {file : CSV UTF-8 con cabeceras de evidencia}
        {--dry-run : Valida todas las filas sin persistir}';

    protected $description = 'Importa en lote evidencia auditada de jefes de tienda';

    public function handle(DelegationManagerEvidenceService $evidence): int
    {
        try {
            $rows = $this->readCsv((string) $this->argument('file'));
            $validated = [];
            foreach ($rows as $index => $row) {
                try {
                    $validated[] = $evidence->validate($row);
                } catch (ValidationException $exception) {
                    foreach ($exception->errors()['evidence'] ?? ['Evidencia no valida.'] as $error) {
                        $this->error('Fila '.($index + 2).': '.$error);
                    }
                }
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (count($validated) !== count($rows)) {
            $this->error('Import cancelado: ninguna fila ha sido persistida.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry-run correcto: '.count($validated).' filas validas.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => collect($validated)->each(fn (array $row) => $evidence->record($row)));
        $this->info('Import completado: '.count($validated).' evidencias registradas.');

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se puede leer el CSV indicado.');
        }

        $headers = fgetcsv($handle);
        $required = ['delegation_id', 'delegation_name', 'manager_id', 'manager_name', 'month', 'source', 'reference', 'recorded_by', 'evidence_type'];
        if (! is_array($headers) || array_diff($required, $headers) !== []) {
            fclose($handle);
            throw new RuntimeException('El CSV no contiene todas las cabeceras requeridas.');
        }

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            if (count($headers) !== count($values)) {
                fclose($handle);
                throw new RuntimeException('El CSV contiene una fila con un numero de columnas invalido.');
            }
            $rows[] = array_combine($headers, $values);
        }
        fclose($handle);

        if ($rows === []) {
            throw new RuntimeException('El CSV no contiene evidencias.');
        }

        return $rows;
    }
}
