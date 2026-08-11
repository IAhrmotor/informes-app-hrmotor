<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SeederSecurityTest extends TestCase
{
    public function test_database_seeder_no_crea_identidades_reales_ni_demo_en_produccion(): void
    {
        $source = file_get_contents(__DIR__.'/../../database/seeders/DatabaseSeeder.php');

        $this->assertStringNotContainsString('@hrmotor.com', $source);
        $this->assertStringContainsString("app()->environment(['local', 'testing'])", $source);
        $this->assertMatchesRegularExpression(
            "/environment\(\['local', 'testing'\]\).*DemoLeadsSeeder::class/s",
            $source,
        );
    }
}
