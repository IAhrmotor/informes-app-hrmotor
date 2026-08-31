<?php

namespace App\Console\Commands;

use App\Services\Reports\CommercialCommissions\DelegationManagerEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class RecordDelegationManagerEvidenceCommand extends Command
{
    protected $signature = 'commissions:record-delegation-manager-evidence
        {delegation-id : Salesforce Delegacion__c ID}
        {delegation-name}
        {manager-id : Salesforce User ID}
        {manager-name}
        {month : Mes confirmado Y-m}
        {--source= : Fuente real, por ejemplo salesforce_export o it_confirmation}
        {--reference= : Referencia auditable del documento o ticket}
        {--recorded-by= : Identidad de quien registra la evidencia}
        {--evidence-type=month_end : month_end confirma solo el cierre; full_month confirma toda la cobertura}
        {--dry-run : Valida sin persistir}';

    protected $description = 'Registra una confirmación histórica auditada de jefe de tienda para un mes';

    public function handle(DelegationManagerEvidenceService $evidence): int
    {
        try {
            $validated = $evidence->validate([
                'delegation_id' => $this->argument('delegation-id'),
                'delegation_name' => $this->argument('delegation-name'),
                'manager_id' => $this->argument('manager-id'),
                'manager_name' => $this->argument('manager-name'),
                'month' => $this->argument('month'),
                'source' => $this->option('source'),
                'reference' => $this->option('reference'),
                'recorded_by' => $this->option('recorded-by'),
                'evidence_type' => $this->option('evidence-type'),
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors()['evidence'] ?? ['Evidencia no valida.'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry-run correcto. No se ha persistido evidencia.');

            return self::SUCCESS;
        }

        $evidence->record($validated);

        $this->info('Evidencia registrada. No se han modificado datos en Salesforce.');

        return self::SUCCESS;
    }
}
