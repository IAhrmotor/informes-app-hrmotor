<?php

namespace Tests\Unit;

use App\Support\SimpleXlsxWorkbookWriter;
use Tests\TestCase;
use ZipArchive;

class SimpleXlsxWorkbookWriterTest extends TestCase
{
    public function test_generates_a_workbook_with_one_sheet_per_commission_block(): void
    {
        $path = app(SimpleXlsxWorkbookWriter::class)->write([
            [
                'name' => 'Comerciales',
                'headers' => ['Comercial', 'Comision final'],
                'rows' => [['Javier', 120.50]],
            ],
            [
                'name' => 'Area Managers',
                'headers' => ['Area Manager', 'Comision final'],
                'rows' => [['Oscar', 48.20]],
            ],
        ]);

        try {
            $zip = new ZipArchive();

            $this->assertTrue($zip->open($path) === true);
            $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
            $this->assertNotFalse($zip->locateName('xl/worksheets/sheet2.xml'));
            $this->assertStringContainsString('Oscar', (string) $zip->getFromName('xl/worksheets/sheet2.xml'));
            $this->assertStringContainsString('state="frozen"', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
            $this->assertStringContainsString('<autoFilter ref="A1:B2"/>', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();
        } finally {
            @unlink($path);
        }
    }
}
