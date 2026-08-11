<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Models\SalesforceSaleSnapshot;
use App\Models\SalesforceVehicle;
use App\Models\StockCatalogAlias;
use App\Models\StockCatalogValue;
use App\Models\StockDailySnapshot;
use App\Models\StockDelegation;
use App\Services\Reports\Stock\StockCatalogNormalizer;
use App\Services\Reports\Stock\StockDashboardDatasetService;
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
        $vehicle = SalesforceVehicle::query()->create([
            'salesforce_id' => '01t-stock-ui',
            'state' => 'Disponible',
            'stock_delegation_id' => $delegation->id,
            'purchase_price' => 10000,
            'sale_price' => 14000,
            'entry_date' => '2026-07-01',
            'is_in_stock' => true,
        ]);
        foreach (['2026-07-28' => 'Disponible', '2026-07-29' => 'Reservado', '2026-07-30' => 'Bloqueado'] as $date => $state) {
            StockDailySnapshot::query()->create([
                'snapshot_date' => $date,
                'salesforce_vehicle_id' => $vehicle->id,
                'vehicle_salesforce_id' => $vehicle->salesforce_id,
                'state' => $state,
                'stock_delegation_id' => $delegation->id,
                'delegation_name' => 'Rivas',
            ]);
        }

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock')
            ->assertOk()
            ->assertSee('Análisis integral del stock')
            ->assertSee('Menos de 60 días')
            ->assertSee('Total por tramos')
            ->assertSee('stock-line-chart', false)
            ->assertSee('id="stock-history-panel"', false)
            ->assertSee('data-expandable-list', false)
            ->assertDontSee('Ventas por stock')
            ->assertSee('Calidad del dato')
            ->assertSee('Exportar incidencias a Excel')
            ->assertDontSee('Importar capacidades')
            ->assertDontSee('Stock, capacidad y ventas por delegación');

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=delegations')
            ->assertOk()
            ->assertSee('Badajoz')
            ->assertSee('Stock, capacidad y ventas por delegación')
            ->assertSee('Ventas por perfil')
            ->assertSee('Más vendidos')
            ->assertSee('Menos vendidos')
            ->assertSee('stock-delegation-link', false)
            ->assertSee('data-sortable-table', false)
            ->assertSee('Añadir/quitar columnas')
            ->assertSee('data-column-key="sales_per_stock"', false)
            ->assertSee('stockGeneralModelsByBrand', false)
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
            ->assertSee('Ventas por perfil')
            ->assertSee('Tramos de precio');

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=sales')
            ->assertOk()
            ->assertSee('Stock, capacidad y ventas por delegación')
            ->assertSee('Ventas por perfil')
            ->assertDontSee('Liquidación cliente');

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=recommendations')
            ->assertOk()
            ->assertSee('Simulador para nuevos vehículos')
            ->assertSee('Vehículos propuestos para traslado')
            ->assertSee('name="candidate_plate"', false)
            ->assertSee('stockModelsByBrand', false);

        $this->withSession($this->sessionData($admin))
            ->get('/informes/stock?section=vehicles')
            ->assertOk()
            ->assertSee('Detalle de vehículos')
            ->assertSee('name="vehicle_plate"', false)
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

    public function test_delegacion_y_matricula_se_filtran_antes_de_construir_los_listados(): void
    {
        foreach (['Rivas', 'Alcobendas'] as $index => $name) {
            $delegation = StockDelegation::query()->create([
                'canonical_name' => $name,
                'normalized_key' => str($name)->lower()->ascii()->toString(),
                'capacity_total' => 20,
                'is_commercial' => true,
            ]);
            SalesforceVehicle::query()->create([
                'salesforce_id' => '01t-filter-plate-'.$index,
                'plate' => $index === 0 ? '1234ABC' : '9876XYZ',
                'brand' => 'Ford',
                'model' => $index === 0 ? 'Focus' : 'Fiesta',
                'state' => 'Disponible',
                'stock_delegation_id' => $delegation->id,
                'entry_date' => now()->subDays(70)->toDateString(),
                'is_in_stock' => true,
            ]);
        }

        $service = app(StockDashboardDatasetService::class);
        $delegations = $service->build(['delegation' => 'Rivas'], 'delegations');
        $vehicles = $service->build(['vehicle_plate' => '34a'], 'vehicles');
        $candidates = $service->build(['candidate_plate' => '76x'], 'recommendations');

        $this->assertSame(['Rivas'], $delegations['delegationRows']->pluck('model.canonical_name')->all());
        $this->assertSame(['1234ABC'], $vehicles['detailRows']->pluck('plate')->all());
        $this->assertSame(1, $vehicles['detailTotal']);
        $this->assertSame(['9876XYZ'], $candidates['recommendationRows']->pluck('plate')->all());
        $this->assertSame(1, $candidates['recommendationTotal']);
    }

    public function test_los_tramos_de_antiguedad_son_excluyentes_y_cuadran_con_el_stock(): void
    {
        $delegation = StockDelegation::query()->create([
            'canonical_name' => 'Tramos',
            'normalized_key' => 'tramos',
            'capacity_total' => 20,
            'is_commercial' => true,
        ]);
        foreach ([10, 60, 90, 120, 180, 181, null] as $index => $days) {
            SalesforceVehicle::query()->create([
                'salesforce_id' => '01t-age-'.$index,
                'state' => 'Disponible',
                'stock_delegation_id' => $delegation->id,
                'entry_date' => $days === null ? null : now()->subDays($days)->toDateString(),
                'is_in_stock' => true,
            ]);
        }

        $summary = app(StockDashboardDatasetService::class)->build([], 'summary')['summary'];

        $this->assertSame(1, $summary['age_under_60']);
        $this->assertSame(1, $summary['age_60_90']);
        $this->assertSame(1, $summary['age_90_120']);
        $this->assertSame(2, $summary['age_120_180']);
        $this->assertSame(1, $summary['age_over_180']);
        $this->assertSame(1, $summary['age_unknown']);
        $this->assertSame($summary['total'], $summary['age_bucket_total']);
    }

    public function test_solo_un_usuario_con_permiso_independiente_puede_aprobar_aliases_activos(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $admin = $this->user(ReportUser::ROLE_ADMIN, 'aliases-admin@hrmotor.com');
        $target = StockCatalogValue::query()->create([
            'object_api_name' => 'Product2',
            'field_api_name' => 'PRO_SEL_Marca__c',
            'api_value' => 'Peugeot',
            'label' => 'Peugeot',
            'is_active' => true,
            'synced_at' => now(),
        ]);

        $this->withSession($this->sessionData($admin))
            ->post(route('reports.stock.catalog-aliases.approve'), [
                'field_api_name' => 'PRO_SEL_Marca__c',
                'raw_value' => ' PEUGEOT ',
                'stock_catalog_value_id' => $target->id,
                'rule_name' => 'manual_approved_alias',
                'reason' => 'Variante histórica validada.',
            ])->assertRedirect();

        $alias = StockCatalogAlias::query()->sole();
        $this->assertSame(StockCatalogAlias::APPROVAL_APPROVED, $alias->approval_status);
        $this->assertSame($admin->id, $alias->approved_by_report_user_id);
        $this->assertNotNull($alias->approved_at);
        $this->assertSame('Peugeot', app(StockCatalogNormalizer::class)->canonicalize('brand', 'peugeot')['canonical']);

        $legacy = StockCatalogAlias::query()->create([
            'field_api_name' => 'PRO_SEL_Marca__c',
            'raw_value' => 'Legacy brand',
            'normalized_key' => 'legacy brand',
            'stock_catalog_value_id' => $target->id,
            'rule_name' => 'legacy',
            'reason' => 'Pendiente de aprobación.',
            'approval_status' => StockCatalogAlias::APPROVAL_LEGACY_UNVERIFIED,
        ]);
        $this->assertSame('Legacy brand', app(StockCatalogNormalizer::class)->canonicalize('brand', 'Legacy brand')['canonical']);
        $this->assertNotNull($legacy);
    }

    public function test_rankings_se_ordenan_por_ventas_y_el_simulador_relaciona_marcas_y_modelos(): void
    {
        $delegation = StockDelegation::query()->create([
            'canonical_name' => 'Ventas',
            'normalized_key' => 'ventas',
            'capacity_total' => 30,
            'is_commercial' => true,
        ]);
        foreach ([['Ford', 'Focus'], ['Ford', 'Fiesta'], ['Toyota', 'Yaris'], ['Seat', 'Ibiza']] as $index => [$brand, $model]) {
            SalesforceVehicle::query()->create([
                'salesforce_id' => '01t-rank-'.$index,
                'brand' => $brand,
                'model' => $model,
                'state' => 'Disponible',
                'stock_delegation_id' => $delegation->id,
                'entry_date' => now()->subDays(30)->toDateString(),
                'is_in_stock' => true,
            ]);
        }
        foreach (['Ford', 'Ford', 'Ford', 'Toyota'] as $index => $brand) {
            SalesforceSaleSnapshot::query()->create([
                'opportunity_salesforce_id' => '006-rank-'.$index,
                'signed_date' => now()->toDateString(),
                'stock_delegation_id' => $delegation->id,
                'vehicle_brand' => $brand,
                'vehicle_model' => $brand === 'Ford' ? 'Focus' : 'Yaris',
                'is_valid' => true,
                'captured_at' => now(),
            ]);
        }

        $service = app(StockDashboardDatasetService::class);
        $period = ['date_from' => '2020-01-01', 'date_to' => '2030-01-01'];
        config()->set('stock.ranking_limit', 2);
        $top = $service->build($period, 'delegations');
        $bottom = $service->build([...$period, 'ranking_view' => 'bottom'], 'delegations');
        $all = $service->build([...$period, 'ranking_view' => 'all'], 'delegations');

        $this->assertSame('Ford', $top['rankings']['brand']['rows']->first()['label']);
        $this->assertSame(
            'Seat',
            $bottom['rankings']['brand']['rows']->first()['label'],
            $bottom['rankings']['brand']['rows']->toJson(),
        );
        $this->assertEqualsCanonicalizing(['Fiesta', 'Focus'], $top['filterOptions']['models_by_brand']['Ford']->all());
        $this->assertNotContains('Yaris', $top['filterOptions']['models_by_brand']['Ford']->all());
        $this->assertCount(3, $top['rankings']['brand']['rows']);
        $this->assertCount(3, $bottom['rankings']['brand']['rows']);
        $this->assertCount(3, $all['rankings']['brand']['rows']);
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
