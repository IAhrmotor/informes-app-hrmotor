<?php

namespace App\Console\Commands;

use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use App\Services\Salesforce\SalesforceClient;
use Illuminate\Console\Command;
use Throwable;

class SalesforceTestOpportunitiesCommand extends Command
{
    protected $signature = 'salesforce:test-opportunities';

    protected $description = 'Prueba la consulta base de Opportunities en Salesforce.';

    public function handle(SalesforceClient $client, SalesforceOpportunitySyncService $sync): int
    {
        try {
            $records = $client->query($sync->testSoql());

            $this->info('Conexion Salesforce Opportunities OK.');
            $this->line('Registros devueltos: '.count($records));

            if ($first = $records[0] ?? null) {
                $this->line('Primera oportunidad: '.(data_get($first, 'Name') ?: data_get($first, 'Id')));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Error probando Opportunities.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }
}
