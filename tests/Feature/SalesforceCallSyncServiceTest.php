<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use App\Services\Reports\Calls\CallAgentResolver;
use App\Services\Reports\Calls\CallClassificationRules;
use App\Services\Reports\Calls\CallDescriptionParser;
use App\Services\Reports\Calls\CallPortalNormalizer;
use App\Services\Reports\Calls\SalesforceCallSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Database\Seeders\CallAgentMappingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SalesforceCallSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CallAgentMappingsSeeder::class);
    }

    public function test_guarda_task_como_salesforce_call_con_campos_resueltos(): void
    {
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(function (string $soql): array {
            if (str_contains($soql, 'FROM User')) {
                return [[
                    'Id' => '005-owner',
                    'Name' => 'Comercial Owner',
                    'IsActive' => true,
                    'Profile' => ['Name' => 'Compra/Venta'],
                    'USR_SEL_Delegacion__c' => 'HR MOTOR ALCOBENDAS',
                ]];
            }

            if (str_contains($soql, 'FROM Lead')) {
                return [[
                    'Id' => '00Q-lead',
                    'Portal_Text__c' => 'Web',
                    'LEA_SEL_Fuente_Origen__c' => null,
                    'Fuente_Nuevo__c' => null,
                ]];
            }

            return [[
                'Id' => '00T-call',
                'Subject' => 'Llamada entrante',
                'Description' => "Resultado: ANSWERED\nTipo: Entrante a fijo\nComercial destino: AG1 - Vanesa Germán\nDuracion de la llamada: 80 segundos",
                'Type' => 'Call',
                'Status' => 'Completed',
                'Priority' => 'Normal',
                'ActivityDate' => '2026-05-10',
                'CreatedDate' => '2026-05-10T10:00:00.000Z',
                'LastModifiedDate' => '2026-05-10T10:05:00.000Z',
                'OwnerId' => '005-owner',
                'Owner' => ['Name' => 'Comercial Owner', 'Profile' => ['Name' => 'Compra/Venta']],
                'WhoId' => '00Q-lead',
                'WhatId' => null,
                'CallObject' => 'call-object-1',
                'CallDurationInSeconds' => 80,
                'CallType' => 'Inbound',
                'Portales__c' => 'Web Alcobendas',
            ]];
        });

        $service = new SalesforceCallSyncService(
            $client,
            app(CallDescriptionParser::class),
            app(CallPortalNormalizer::class),
            app(CallAgentResolver::class),
            app(CallClassificationRules::class),
        );

        $result = $service->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));
        $call = SalesforceCall::first();

        $this->assertSame(1, $result['saved']);
        $this->assertSame('Web', $call->portal_resolved);
        $this->assertSame('portal', $call->call_origin);
        $this->assertSame(CallClassificationRules::VERSION, $call->classification_rule_version);
        $this->assertSame('answered', $call->call_status);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame(70, $call->adjusted_duration_seconds);
        $this->assertSame('contact_center', $call->operational_team);
        $this->assertSame('Alcobendas', $call->delegation);
        $this->assertSame('Zona Sur y Centro', $call->zone);
    }

    public function test_portal_no_clasificado_de_task_usa_prioridad_legacy_del_lead_relacionado(): void
    {
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(function (string $soql): array {
            if (str_contains($soql, 'FROM User')) {
                return [[
                    'Id' => '005-owner',
                    'Name' => 'Comercial Owner',
                    'IsActive' => true,
                    'Profile' => ['Name' => 'Compra/Venta'],
                    'USR_SEL_Delegacion__c' => 'HR MOTOR ALCOBENDAS',
                ]];
            }

            if (str_contains($soql, 'FROM Lead')) {
                $this->assertStringContainsString('Portal_Text__c', $soql);
                $this->assertStringContainsString('LEA_SEL_Fuente_Origen__c', $soql);
                $this->assertStringContainsString('Fuente_Nuevo__c', $soql);
                $this->assertStringContainsString('Fuente_origen__c', $soql);

                return [[
                    'Id' => '00Q-lead-conflict',
                    'Fuente_origen__c' => 'Coches.net',
                    'Portal_Text__c' => 'Google Maps',
                    'LEA_SEL_Fuente_Origen__c' => 'Coches.net',
                    'Fuente_Nuevo__c' => 'Wallapop',
                ]];
            }

            return [[
                'Id' => '00T-call-conflict',
                'Subject' => 'Llamada entrante',
                'Description' => "Resultado: ANSWERED\nTipo: Entrante a fijo\nComercial destino: AG1 - Vanesa Germán\nDuracion de la llamada: 80 segundos",
                'Type' => 'Call',
                'Status' => 'Completed',
                'Priority' => 'Normal',
                'ActivityDate' => '2026-05-10',
                'CreatedDate' => '2026-05-10T10:00:00.000Z',
                'LastModifiedDate' => '2026-05-10T10:05:00.000Z',
                'OwnerId' => '005-owner',
                'Owner' => ['Name' => 'Comercial Owner', 'Profile' => ['Name' => 'Compra/Venta']],
                'WhoId' => '00Q-lead-conflict',
                'CallObject' => 'call-object-conflict',
                'CallDurationInSeconds' => 80,
                'CallType' => 'Inbound',
                'Portales__c' => '3CX',
            ]];
        });

        $service = new SalesforceCallSyncService(
            $client,
            app(CallDescriptionParser::class),
            app(CallPortalNormalizer::class),
            app(CallAgentResolver::class),
            app(CallClassificationRules::class),
        );

        $service->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $call = SalesforceCall::query()->where('salesforce_id', '00T-call-conflict')->firstOrFail();
        $this->assertSame('Coches.net', $call->portal_resolved);
        $this->assertSame('lead', $call->portal_resolution_source);
        $this->assertSame('portal', $call->call_origin);
        $this->assertSame(70, $call->adjusted_duration_seconds);
        $this->assertFalse($call->is_overflow);
        $this->assertSame('Fuente_origen__c', data_get($call->parse_debug, 'portal_debug.effective_source_field'));
    }
}
