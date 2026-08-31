<?php

namespace App\Console\Commands;

use App\Services\Reports\CommercialCommissions\Sync\SalesforceDelegationManagerSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SalesforceSyncDelegationManagersCommand extends Command
{
    protected $signature = 'salesforce:sync-delegation-managers {--from=2026-07-01}';

    protected $description = 'Sincroniza responsables e historial verificable de delegaciones desde Salesforce';

    public function handle(SalesforceDelegationManagerSyncService $sync): int
    {
        $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->option('from'));
        if (! $from) {
            $this->error('La fecha --from debe usar el formato Y-m-d.');

            return self::FAILURE;
        }

        $result = $sync->sync($from->startOfDay());
        $this->info(sprintf('Delegaciones: %d; eventos históricos: %d; filas guardadas: %d.', $result['delegations'], $result['history_events'], $result['rows_saved']));

        return self::SUCCESS;
    }
}
