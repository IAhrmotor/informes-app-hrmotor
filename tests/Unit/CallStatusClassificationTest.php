<?php

namespace Tests\Unit;

use App\Services\Reports\Calls\SalesforceCallSyncService;
use Tests\TestCase;

class CallStatusClassificationTest extends TestCase
{
    public function test_clasifica_resultados_de_llamada(): void
    {
        $service = app(SalesforceCallSyncService::class);

        $this->assertSame('answered', $service->classifyStatus('ANSWERED'));

        foreach (['NO ANSWER', 'FAILED', 'BUSY', 'ABANDONED', '', null] as $result) {
            $this->assertSame('not_answered', $service->classifyStatus($result));
        }
    }
}
