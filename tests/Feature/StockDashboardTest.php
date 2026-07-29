<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Models\SalesforceVehicle;
use App\Models\StockDelegation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StockDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_es_visible_para_admin_y_aparece_en_permisos(): void
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
            ->assertSee('Situación actual del stock')
            ->assertSee('Importar capacidades')
            ->assertSee('Edición manual de capacidades')
            ->assertSee('Rivas');

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
