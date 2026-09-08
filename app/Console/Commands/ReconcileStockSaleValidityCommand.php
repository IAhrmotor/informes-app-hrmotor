<?php

namespace App\Console\Commands;

use App\Services\Reports\Stock\StockSaleValidityService;
use Illuminate\Console\Command;

class ReconcileStockSaleValidityCommand extends Command
{
    protected $signature = 'stock:reconcile-sale-validity';

    protected $description = 'Recalcula exclusivamente la validez de los snapshots de ventas Stock existentes.';

    public function handle(StockSaleValidityService $service): int
    {
        $result = $service->reconcile();

        $this->line('STOCK_SALE_VALIDITY_METRICS='.json_encode(
            $result,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return self::SUCCESS;
    }
}
