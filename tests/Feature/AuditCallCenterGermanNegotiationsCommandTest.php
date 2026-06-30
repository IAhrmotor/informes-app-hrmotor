<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceTasacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditCallCenterGermanNegotiationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audita_tasaciones_german_y_desglosa_motivos_de_exclusion(): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-IN',
            'name' => 'Opportunity incluida',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-10',
            'raw_payload' => [],
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-CV-NO',
            'name' => 'Opportunity cv no',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'cv_signed' => false,
            'cv_signed_date' => '2026-05-10',
            'raw_payload' => [],
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-BEFORE',
            'name' => 'Opportunity before',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-28',
            'raw_payload' => [],
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-NO-DATE',
            'name' => 'Opportunity sin fecha',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'cv_signed' => true,
            'cv_signed_date' => null,
            'raw_payload' => [],
        ]);

        foreach ([
            [
                'id' => 'a02-in',
                'opp' => '006-IN',
                'tracking' => 'German',
                'neg1' => 'Seguimiento 1',
            ],
            [
                'id' => 'a02-empty',
                'opp' => '006-IN',
                'tracking' => 'German',
                'neg1' => null,
            ],
            [
                'id' => 'a02-missing-opp',
                'opp' => null,
                'tracking' => 'German',
                'neg1' => 'Seguimiento 2',
            ],
            [
                'id' => 'a02-no-date',
                'opp' => '006-NO-DATE',
                'tracking' => 'German',
                'neg1' => 'Seguimiento 3',
            ],
            [
                'id' => 'a02-cv-no',
                'opp' => '006-CV-NO',
                'tracking' => 'German',
                'neg1' => 'Seguimiento 4',
            ],
            [
                'id' => 'a02-before',
                'opp' => '006-BEFORE',
                'tracking' => 'German',
                'neg1' => 'Seguimiento 5',
            ],
            [
                'id' => 'a02-other',
                'opp' => '006-IN',
                'tracking' => 'Otro',
                'neg1' => 'No German',
            ],
        ] as $row) {
            SalesforceTasacion::query()->create([
                'salesforce_id' => $row['id'],
                'name' => 'Tasacion '.$row['id'],
                'created_date' => '2026-05-10 10:00:00',
                'opportunity_salesforce_id' => $row['opp'],
                'opportunity_name' => $row['opp'],
                'contract_signed_date' => null,
                'cv_signed' => false,
                'tracking_name' => $row['tracking'],
                'negotiation_1' => $row['neg1'],
                'source_query_profile' => 'oportunidad_relation',
                'raw_payload' => [
                    'Seguimiento__c' => $row['tracking'],
                    'Negociaci_n_1__c' => $row['neg1'],
                    'Oportunidad__c' => $row['opp'],
                ],
            ]);
        }

        $this->artisan('reports:audit-call-center-german', [
            '--month' => '2026-05',
            '--examples' => 1,
        ])
            ->expectsOutputToContain('Tasaciones sincronizadas: 7')
            ->expectsOutputToContain('Tasaciones con seguimiento German: 6')
            ->expectsOutputToContain('Entran en comision: 1')
            ->expectsOutputToContain('Quedan fuera: 5')
            ->expectsOutputToContain('Entra en comisión')
            ->expectsOutputToContain('Negociación 1 vacía')
            ->expectsOutputToContain('Sin oportunidad relacionada')
            ->expectsOutputToContain('Sin fecha firma contrato')
            ->expectsOutputToContain('Contrato CV firmado = false/vacío')
            ->expectsOutputToContain('Fecha firma contrato antes del rango')
            ->assertExitCode(0);
    }
}
