<?php

namespace App\Console\Commands;

use App\Services\Reports\Stock\StockCapacityImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportStockCapacitiesCommand extends Command
{
    protected $signature = 'stock:import-capacities
        {file : Ruta del CSV o XLSX}
        {--delimiter=, : Delimitador cuando el archivo es CSV}';

    protected $description = 'Carga la capacidad máxima de cada tienda desde la columna Plazas totales.';

    public function handle(StockCapacityImportService $importer): int
    {
        $path = $this->resolvePath((string) $this->argument('file'));
        if ($path === null) {
            $this->error('No se encontró el archivo indicado.');

            return self::FAILURE;
        }

        try {
            $result = $importer->import($path, (string) $this->option('delimiter'));
            $this->info("Capacidades importadas: {$result['imported']}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolvePath(string $path): ?string
    {
        foreach ([$path, base_path($path), storage_path('app/'.$path), storage_path('app/private/'.$path)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
