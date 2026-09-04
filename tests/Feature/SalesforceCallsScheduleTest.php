<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesforceCallsScheduleTest extends TestCase
{
    public function test_salesforce_calls_sync_is_scheduled_once_before_seo(): void
    {
        $scheduler = file_get_contents(base_path('routes/console.php'));
        $command = "Schedule::command('salesforce:sync-calls --days=7')";

        $this->assertSame(1, substr_count($scheduler, $command));
        $this->assertStringNotContainsString('salesforce:sync-calls --days=7 --fresh', $scheduler);
        $this->assertStringNotContainsString('salesforce:sync-calls --days=120', $scheduler);

        $syncPosition = strpos($scheduler, $command);
        $this->assertNotFalse($syncPosition);
        $syncIdentifierPosition = strpos($scheduler, "'salesforce-sync-calls'", $syncPosition);
        $this->assertNotFalse($syncIdentifierPosition);

        $monitorPosition = strrpos(substr($scheduler, 0, $syncPosition), '$monitor(');
        $this->assertNotFalse($monitorPosition);
        $syncConfiguration = substr($scheduler, $monitorPosition, $syncIdentifierPosition - $monitorPosition);

        $this->assertStringContainsString('$monitor(', $syncConfiguration);
        $this->assertStringContainsString("dailyAt('04:45')", $syncConfiguration);
        $this->assertStringContainsString("timezone('Europe/Madrid')", $syncConfiguration);
        $this->assertStringContainsString('withoutOverlapping(60)', $syncConfiguration);

        $seoCommand = "Schedule::command('seo:sync-search-console --days=120')";
        $seoPosition = strpos($scheduler, $seoCommand);
        $this->assertNotFalse($seoPosition);
        $this->assertLessThan($seoPosition, $syncPosition);
    }
}
