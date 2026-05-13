<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceClient;
use Illuminate\Console\Command;
use Throwable;

class SalesforceTestConnectionCommand extends Command
{
    protected $signature = 'salesforce:test';

    protected $description = 'Prueba la conexión OAuth y una consulta SOQL básica contra Salesforce.';

    public function handle(SalesforceClient $client): int
    {
        try {
            $records = $client->query('SELECT Id, Name FROM User LIMIT 1');

            $this->info('Conexión Salesforce OK.');
            $this->line('Registros devueltos: '.count($records));

            if (! empty($records[0])) {
                $this->line('Primer usuario: '.($records[0]['Name'] ?? $records[0]['Id']));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Error conectando con Salesforce.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }
}
