<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Models\SalesforceSaleSnapshot;
use App\Models\SalesforceVehicle;
use App\Models\StockDelegation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

class StockDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_es_visible_para_admin_y_las_secciones_son_pestanas_independientes(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $admin = $this->user(ReportUser::ROLE_ADMIN, 'admin-stock@hrmotor.com');
        $delegation = StockDelegation::query()->create([
            'canonical_name' => 'Rivas',
            'normalized_key' => 'rivas',
            'capacity_total' => 100,
            'is_commercial' => true,
        ]);
        SalesforceVehicle::query()->create([
            'salesforce_id' => '01t-stock-ui',
            'state' => 'Disponible',
            'stock_delegation_id' => $delegation->id,
            'purchase_price' => 10000,
            'sale_price' => 14000,
            'entry_date' => '2026-07-01',
            'is_in_stock' => true,
        ]);

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock')
            ->assertOk()
            ->assertSee('Análisis integral del stock')
            ->assertSee('Calidad del dato')
            ->assertSee('Exportar incidencias a Excel')
            ->assertDontSee('Importar capacidades')
            ->assertDontSee('Stock, capacidad y ventas por delegación');

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=delegations')
            ->assertOk()
            ->assertSee('Badajoz')
            ->assertSee('Stock, capacidad y ventas por delegación')
            ->assertSee('data-sortable-table', false)
            ->assertSee('Rivas')
            ->assertDontSee('Calidad del dato')
            ->assertDontSee('Importar capacidades');

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=capacities')
            ->assertOk()
            ->assertSee('Badajoz')
            ->assertSee('Importar capacidades')
            ->assertSee('Edición manual de capacidades')
            ->assertDontSee('Calidad del dato')
            ->assertDontSee('Stock, capacidad y ventas por delegación');

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=rankings')
            ->assertOk()
            ->assertSee('Rankings de rendimiento')
            ->assertSee('Tramos de precio');

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=sales')
            ->assertOk()
            ->assertSee('Ventas firmadas y operaciones de cambio')
            ->assertSee('Liquidación cliente');

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=recommendations')
            ->assertOk()
            ->assertSee('Simulador para nuevos vehículos')
            ->assertSee('Vehículos disponibles candidatos a traslado');

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=vehicles')
            ->assertOk()
            ->assertSee('Detalle de vehículos')
            ->assertSee('Delegaciones recomendadas');

        $this->withSession($this->sessionData($admin))
            ->get('/informes/permisos-informes')
            ->assertOk()
            ->assertSee('Stock')
            ->assertSee('reports.stock.index');
    }

    public function test_viewer_no_puede_abrir_stock_por_defecto(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $viewer = $this->user(ReportUser::ROLE_VIEWER, 'viewer-stock@hrmotor.com');

        $this->withSession($this->sessionData($viewer))
            ->get('/informes/stock')
            ->assertRedirect('/informes/leads');
    }

    public function test_admin_puede_editar_capacidades_manualmente(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $admin = $this->user(ReportUser::ROLE_ADMIN, 'admin-capacity@hrmotor.com');
        $delegation = StockDelegation::query()->create([
            'canonical_name' => 'Rivas',
            'normalized_key' => 'rivas',
            'is_commercial' => false,
        ]);

        $this->withSession($this->sessionData($admin))
            ->put('/informes/stock/capacidades', [
                'capacities' => [$delegation->id => 125],
            ])
            ->assertRedirect('/informes/stock?section=capacities');

        $this->assertDatabaseHas('stock_delegations', [
            'id' => $delegation->id,
            'capacity_total' => 125,
            'capacity_source_name' => 'Edición manual',
            'is_commercial' => true,
        ]);
    }

    public function test_admin_puede_importar_csv_de_capacidades_desde_el_front(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $admin = $this->user(ReportUser::ROLE_ADMIN, 'admin-upload@hrmotor.com');
        $file = UploadedFile::fake()->createWithContent(
            'capacidades.csv',
            "Tienda;Parking taller;Plazas totales\nRivas;5;130\n",
        );

        $this->withSession($this->sessionData($admin))
            ->post('/informes/stock/capacidades/importar', [
                'capacity_file' => $file,
                'delimiter' => ';',
            ])
            ->assertRedirect('/informes/stock?section=capacities')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('stock_delegations', [
            'canonical_name' => 'Rivas',
            'capacity_total' => 130,
            'is_commercial' => true,
        ]);
    }

    public function test_solo_las_ubicaciones_acordadas_se_marcan_como_no_comerciales(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $admin = $this->user(ReportUser::ROLE_ADMIN, 'admin-commercial@hrmotor.com');

        foreach (['HR MOTOR MANTENIMIENTO', 'Tienda comercial sin zona'] as $index => $name) {
            $delegation = StockDelegation::query()->create([
                'canonical_name' => $name,
                'normalized_key' => str($name)->lower()->ascii()->replaceMatches('/\s+/', ' ')->toString(),
                'is_commercial' => false,
            ]);
            SalesforceVehicle::query()->create([
                'salesforce_id' => '01t-location-'.$index,
                'stock_delegation_id' => $delegation->id,
                'state' => 'Disponible',
                'is_in_stock' => true,
            ]);
        }

        $response = $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=delegations')
            ->assertOk()
            ->assertSee('Tienda comercial sin zona')
            ->assertSee('HR MOTOR MANTENIMIENTO');

        $this->assertSame(1, substr_count($response->getContent(), 'Ubicación no comercial'));
        $response->assertSeeInOrder(['Tienda comercial sin zona', 'HR MOTOR MANTENIMIENTO']);
    }

    public function test_admin_exporta_calidad_en_un_excel_con_una_hoja_por_incidencia(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $admin = $this->user(ReportUser::ROLE_ADMIN, 'admin-quality@hrmotor.com');
        SalesforceVehicle::query()->create([
            'salesforce_id' => '01t-quality',
            'plate' => '1234ABC',
            'state' => 'Disponible',
            'is_in_stock' => true,
        ]);
        SalesforceSaleSnapshot::query()->create([
            'opportunity_salesforce_id' => '006-quality',
            'vehicle_salesforce_id' => '01t-quality',
            'captured_at' => now(),
        ]);

        $response = $this->withSession($this->sessionData($admin))
            ->get('/informes/stock/exportar/calidad-dato.xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;

        try {
            $this->assertTrue($zip->open($path) === true);
            $workbook = (string) $zip->getFromName('xl/workbook.xml');
            $this->assertSame(20, substr_count($workbook, '<sheet '));
            $this->assertStringContainsString('Stock sin entrada', $workbook);
            $this->assertStringContainsString('Ventas sin firma', $workbook);
            $this->assertStringContainsString('01t-quality', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
            $this->assertStringContainsString('1234ABC', (string) $zip->getFromName('xl/worksheets/sheet9.xml'));
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    public function test_filtros_visuales_reducen_el_detalle_de_vehiculos(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $admin = $this->user(ReportUser::ROLE_ADMIN, 'admin-filter-stock@hrmotor.com');
        foreach (['Ford' => 'Focus', 'Toyota' => 'Yaris'] as $brand => $model) {
            SalesforceVehicle::query()->create([
                'salesforce_id' => '01t-filter-'.$brand,
                'plate' => strtoupper(substr($brand, 0, 3)).'123',
                'brand' => $brand,
                'model' => $model,
                'state' => 'Disponible',
                'sale_price' => 15000,
                'is_in_stock' => true,
            ]);
        }

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=vehicles&brand=Ford')
            ->assertOk()
            ->assertSee('Ford')
            ->assertSee('Focus')
            ->assertSee('Mostrando 1 de 1 vehículos');
    }

    private function user(string $role, string $email): ReportUser
    {
        return ReportUser::query()->create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function sessionData(ReportUser $user): array
    {
        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
        ];
    }
}
