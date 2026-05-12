<?php

namespace App\Console\Commands;

use App\Models\LeadNormalized;
use App\Models\LeadRaw;
use App\Services\Reports\Leads\LeadNormalizationService;
use Illuminate\Console\Command;

class NormalizeLeadsCommand extends Command
{
    protected $signature = 'leads:normalize
        {--fresh : Borra leads_normalized antes de normalizar}
        {--limit= : Limita el número de leads a procesar}';

    protected $description = 'Normaliza leads brutos de Salesforce para el dashboard comercial';

    public function handle(LeadNormalizationService $normalizer): int
    {
        if ($this->option('fresh')) {
            LeadNormalized::query()->delete();
            $this->info('Tabla leads_normalized vaciada.');
        }

        $query = LeadRaw::query()
            ->orderBy('id');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No hay leads brutos para normalizar.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $created = 0;
        $updated = 0;

        $query->chunkById(500, function ($leads) use ($normalizer, $bar, &$created, &$updated) {
            foreach ($leads as $lead) {
                $payload = $lead->toArray();

                $normalized = $normalizer->normalize($payload);

                $record = LeadNormalized::updateOrCreate(
                    ['lead_raw_id' => $lead->id],
                    array_merge($normalized, [
                        'lead_created_at' => $lead->lead_created_at,
                    ])
                );

                $record->wasRecentlyCreated ? $created++ : $updated++;

                $bar->advance();
            }
        });

        $bar->finish();

        $this->newLine(2);
        $this->info('Normalización completada.');
        $this->line("Creados: {$created}");
        $this->line("Actualizados: {$updated}");

        return self::SUCCESS;
    }
}