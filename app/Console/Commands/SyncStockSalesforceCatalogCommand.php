<?php

namespace App\Console\Commands;

use App\Services\Reports\Stock\SalesforceStockCatalogSyncService;
use Illuminate\Console\Command;

class SyncStockSalesforceCatalogCommand extends Command
{
    protected $signature = 'stock:sync-salesforce-catalog';
    protected $description = 'Sincroniza valores picklist activos de Product2 como catálogo canónico de Stock.';

    public function handle(SalesforceStockCatalogSyncService $service): int
    {
        $result = $service->sync();
        $this->info('Catálogo Salesforce sincronizado. Valores guardados: '.$result['values_saved']);

        return self::SUCCESS;
    }
}
