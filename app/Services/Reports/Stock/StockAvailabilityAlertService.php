<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceVehicle;
use App\Models\StockAvailabilityAlert;
use App\Models\StockDelegation;
use App\Services\Salesforce\SalesforceClient;
use Illuminate\Support\Facades\Mail;
use Throwable;

class StockAvailabilityAlertService
{
    public function __construct(
        private readonly SalesforceClient $salesforce,
        private readonly StockDelegationNormalizer $normalizer,
    ) {}

    public function evaluate(): array
    {
        $result = ['opened' => 0, 'resolved' => 0, 'errors' => 0];
        $available = SalesforceVehicle::query()
            ->where('is_in_stock', true)
            ->where('state', 'Disponible')
            ->selectRaw('stock_delegation_id, COUNT(*) as total')
            ->groupBy('stock_delegation_id')
            ->pluck('total', 'stock_delegation_id');

        StockDelegation::query()->whereNotNull('capacity_total')->each(
            function (StockDelegation $delegation) use ($available, &$result): void {
                if (! $this->normalizer->isCommercial($delegation->canonical_name)) {
                    return;
                }
                $active = StockAvailabilityAlert::query()
                    ->where('stock_delegation_id', $delegation->id)
                    ->where('state', 'open')
                    ->latest('id')
                    ->first();
                $hasAvailable = (int) ($available[$delegation->id] ?? 0) > 0;

                if ($hasAvailable) {
                    if ($active) {
                        $this->resolve($active, $result);
                    }

                    return;
                }

                $active ??= StockAvailabilityAlert::query()->create([
                    'stock_delegation_id' => $delegation->id,
                    'state' => 'open',
                    'opened_at' => now(),
                ]);
                if ($active->wasRecentlyCreated) {
                    $result['opened']++;
                }
                $this->notify($active, $delegation, $result);
            },
        );

        return $result;
    }

    private function notify(StockAvailabilityAlert $alert, StockDelegation $delegation, array &$result): void
    {
        try {
            if (! $alert->salesforce_task_id) {
                $taskId = $this->salesforce->create('Task', [
                    'Subject' => 'Alerta: '.$delegation->canonical_name.' sin vehículos disponibles',
                    'Status' => 'Not Started',
                    'Priority' => 'High',
                    'ActivityDate' => now()->toDateString(),
                    'Description' => 'La delegación tiene cero vehículos en estado Disponible. Reservados y bloqueados no cuentan como stock comercial.',
                ]);
                $alert->update(['salesforce_task_id' => $taskId, 'task_created_at' => now()]);
            }
            if (! $alert->email_sent_at) {
                Mail::raw(
                    "La delegación {$delegation->canonical_name} se ha quedado sin vehículos disponibles.\n\nSe ha registrado una Task en Salesforce. El aviso se cerrará automáticamente cuando recupere stock comercial.",
                    fn ($message) => $message
                        ->to((string) config('stock.alert_email'))
                        ->subject('Alerta de stock: '.$delegation->canonical_name.' sin disponibles'),
                );
                $alert->update(['email_sent_at' => now()]);
            }
            $alert->update(['last_error' => null]);
        } catch (Throwable $exception) {
            $alert->update(['last_error' => $exception->getMessage()]);
            $result['errors']++;
        }
    }

    private function resolve(StockAvailabilityAlert $alert, array &$result): void
    {
        $alert->update(['state' => 'resolved', 'resolved_at' => now(), 'last_error' => null]);
        try {
            if ($alert->salesforce_task_id) {
                $this->salesforce->update('Task', $alert->salesforce_task_id, ['Status' => 'Completed']);
            }
        } catch (Throwable $exception) {
            $alert->update(['last_error' => $exception->getMessage()]);
            $result['errors']++;
        }
        $result['resolved']++;
    }
}
