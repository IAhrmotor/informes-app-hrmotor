<?php

namespace Tests\Feature;

use Tests\TestCase;

class CallNoCsvDependencyTest extends TestCase
{
    public function test_modulo_llamadas_no_usa_csv_ni_tablas_legacy(): void
    {
        $files = collect(glob(app_path('Services/Reports/Calls/*.php')))
            ->merge(glob(app_path('Console/Commands/*Calls*.php')))
            ->merge(glob(app_path('Http/Controllers/Reports/Calls/*.php')));

        $content = $files->map(fn (string $file) => file_get_contents($file))->implode("\n");

        $this->assertStringNotContainsString('csv', strtolower($content));
        $this->assertStringNotContainsString('LeadRaw', $content);
        $this->assertStringNotContainsString('leads_raw', $content);
    }
}
