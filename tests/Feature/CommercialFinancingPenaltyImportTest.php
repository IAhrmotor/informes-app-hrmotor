<?php

namespace Tests\Feature;

use App\Models\CommercialFinancingPenalty;
use App\Models\CommercialFinancingPenaltyImport;
use App\Models\ReportUser;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialFinancingPenaltyImportService;
use App\Services\Reports\CommercialCommissions\CommercialFinancingPenaltyService;
use App\Services\Reports\CommercialCommissions\Import\CommercialFinancingPenaltyImportException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class CommercialFinancingPenaltyImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_importa_por_id_mes_y_sustituye_el_mes_previamente_cargado(): void
    {
        SalesforceUser::create([
            'salesforce_id' => '005-COMMERCIAL',
            'name' => 'Comercial Uno',
            'email' => 'comercial@hrmotor.com',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        $importer = app(CommercialFinancingPenaltyImportService::class);
        $importer->import($this->xlsxUpload([
            ['Mes comision', 'Nombre comercial', 'ID comercial', 'descontar comercial 4%'],
            ['2026-06', 'Comercial Uno', '005-COMMERCIAL', '100,50'],
            ['Junio 2026', 'Comercial Uno', '005-COMMERCIAL', -200],
        ]), null);

        $ledger = app(CommercialFinancingPenaltyService::class)->forMonth(CarbonImmutable::parse('2026-06-01'));
        $this->assertEquals(-300.5, $ledger['amounts_by_user_id']['005-COMMERCIAL']);
        $this->assertDatabaseCount('commercial_financing_penalties', 2);

        $importer->import($this->xlsxUpload([
            ['Mes comision', 'Nombre comercial', 'ID Salesforce', 'descontar a comercial 4%'],
            ['2026-06-01', 'Comercial Uno', '005-COMMERCIAL', 50],
        ]), null);

        $ledger = app(CommercialFinancingPenaltyService::class)->forMonth(CarbonImmutable::parse('2026-06-01'));
        $this->assertEquals(-50.0, $ledger['amounts_by_user_id']['005-COMMERCIAL']);
        $this->assertSame(1, CommercialFinancingPenalty::query()->where('is_active', true)->count());
        $this->assertSame(2, CommercialFinancingPenalty::query()->where('is_active', false)->count());
    }

    public function test_rechaza_archivo_sin_id_comercial(): void
    {
        $this->expectException(CommercialFinancingPenaltyImportException::class);

        app(CommercialFinancingPenaltyImportService::class)->import($this->xlsxUpload([
            ['Mes comision', 'Nombre comercial', 'descontar comercial 4%'],
            ['2026-06', 'Comercial Uno', 100],
        ]), null);
    }

    public function test_resta_la_penalizacion_del_total_final_del_comercial(): void
    {
        SalesforceUser::create([
            'salesforce_id' => '005-APPRAISER-PENALTY',
            'name' => 'Tasador Penalizado',
            'email' => 'tasador@hrmotor.com',
            'profile_name' => 'Standard User',
            'is_active' => true,
            'commission_appraiser' => true,
        ]);
        SalesforceOpportunity::create([
            'salesforce_id' => 'OPP-APPRAISER-PENALTY',
            'name' => 'Venta tasador',
            'owner_id' => '005-APPRAISER-PENALTY',
            'owner_name' => 'Tasador Penalizado',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'gestion_de_venta' => false,
        ]);
        $import = CommercialFinancingPenaltyImport::create([
            'original_filename' => 'penalizaciones.xlsx',
            'rows_read' => 1,
            'rows_imported' => 1,
            'commission_months' => ['2026-06-01'],
        ]);
        CommercialFinancingPenalty::create([
            'import_id' => $import->id,
            'commission_month' => '2026-06-01',
            'commercial_email' => 'tasador@hrmotor.com',
            'commercial_name' => 'Tasador Penalizado',
            'salesforce_user_id' => '005-APPRAISER-PENALTY',
            'amount' => -200,
            'is_active' => true,
        ]);

        $row = collect(app(CommercialCommissionDashboardService::class)->build('2026-06')['summary_rows'])
            ->firstWhere('commercial_id', '005-APPRAISER-PENALTY');

        $this->assertNotNull($row);
        $this->assertEquals(-200.0, $row['financing_cancellation_penalty_amount']);
        $this->assertEquals(-140.0, $row['final_commission']);
    }

    public function test_admin_puede_abrir_penalizaciones_con_filas_sin_match(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $import = CommercialFinancingPenaltyImport::create([
            'original_filename' => 'penalizaciones.xlsx',
            'rows_read' => 1,
            'rows_imported' => 1,
            'commission_months' => ['2026-06-01'],
        ]);
        CommercialFinancingPenalty::create([
            'import_id' => $import->id,
            'commission_month' => '2026-06-01',
            'commercial_name' => 'Sin match',
            'salesforce_user_id' => '005-SIN-MATCH',
            'amount' => -50,
            'is_active' => true,
        ]);

        $this->withSession([
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => 'admin@hrmotor.com',
        ])
            ->get('/informes/penalizaciones-financiacion')
            ->assertOk()
            ->assertSee('005-SIN-MATCH');
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function xlsxUpload(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'commission-penalties-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Penalizaciones" sheetId="1" r:id="rId1"/></sheets></workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>
XML);
        $xmlRows = [];

        foreach ($rows as $rowIndex => $row) {
            $cells = [];

            foreach ($row as $columnIndex => $value) {
                $reference = chr(65 + $columnIndex).($rowIndex + 1);
                $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells[] = '<c r="'.$reference.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
            }

            $xmlRows[] = '<row r="'.($rowIndex + 1).'">'.implode('', $cells).'</row>';
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $xmlRows).'</sheetData></worksheet>');
        $zip->close();
        $content = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('penalizaciones.xlsx', $content);
    }
}
